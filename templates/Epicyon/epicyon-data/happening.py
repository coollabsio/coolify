__filename__ = "happening.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Core"

import os
from uuid import UUID
from hashlib import md5
from datetime import datetime
from datetime import timedelta
from flags import is_reminder
from flags import is_public_post
from utils import replace_strings
from utils import date_from_numbers
from utils import date_from_string_format
from utils import acct_handle_dir
from utils import load_json
from utils import save_json
from utils import locate_post
from utils import has_object_dict
from utils import acct_dir
from utils import remove_html
from utils import get_display_name
from utils import delete_post
from utils import get_status_number
from utils import get_full_domain
from utils import text_in_file
from utils import remove_eol
from filters import is_filtered
from context import get_individual_post_context
from session import get_method
from auth import create_basic_auth_header
from conversation import post_id_to_convthread_id


def _strings_are_digits(strings_list: []) -> bool:
    """Are the given list of strings digits?
    """
    for text in strings_list:
        if not text.isdigit():
            return False
    return True


def _dav_date_from_string(timestamp: str) -> str:
    """Returns a datetime from a caldav date
    """
    timestamp_year = timestamp[:4]
    timestamp_month = timestamp[4:][:2]
    timestamp_day = timestamp[6:][:2]
    timestamp_hour = timestamp[9:][:2]
    timestamp_min = timestamp[11:][:2]
    timestamp_sec = timestamp[13:][:2]

    if not _strings_are_digits([timestamp_year, timestamp_month,
                                timestamp_day, timestamp_hour,
                                timestamp_min, timestamp_sec]):
        return None
    if int(timestamp_year) < 2020 or int(timestamp_year) > 2100:
        return None
    published = \
        timestamp_year + '-' + timestamp_month + '-' + timestamp_day + 'T' + \
        timestamp_hour + ':' + timestamp_min + ':' + timestamp_sec + 'Z'
    return published


def _valid_uuid(test_uuid: str, version: int):
    """Check if uuid_to_test is a valid UUID
    """
    try:
        uuid_obj = UUID(test_uuid, version=version)
    except ValueError:
        return False

    return str(uuid_obj) == test_uuid


def _remove_event_from_timeline(event_id: str,
                                tl_events_filename: str) -> None:
    """Removes the given event Id from the timeline
    """
    if not text_in_file(event_id + '\n', tl_events_filename):
        return
    events_timeline = ''
    with open(tl_events_filename, 'r',
              encoding='utf-8') as fp_tl:
        events_timeline = fp_tl.read().replace(event_id + '\n', '')

    if events_timeline:
        try:
            with open(tl_events_filename, 'w+',
                      encoding='utf-8') as fp2:
                fp2.write(events_timeline)
        except OSError:
            print('EX: ERROR: unable to save events timeline')
    elif os.path.isfile(tl_events_filename):
        try:
            os.remove(tl_events_filename)
        except OSError:
            print('EX: ERROR: unable to remove events timeline')


def save_event_post(base_dir: str, handle: str, post_id: str,
                    event_json: {}) -> bool:
    """Saves an event to the calendar and/or the events timeline
    If an event has extra fields, as per Mobilizon,
    Then it is saved as a separate entity and added to the
    events timeline
    See https://framagit.org/framasoft/mobilizon/-/blob/
    master/lib/federation/activity_stream/converter/event.ex
    """
    handle_dir = acct_handle_dir(base_dir, handle)
    if not os.path.isdir(handle_dir):
        print('WARN: Account does not exist at ' + handle_dir)
    calendar_path = handle_dir + '/calendar'
    if not os.path.isdir(calendar_path):
        os.mkdir(calendar_path)

    # get the year, month and day from the event
    event_time = date_from_string_format(event_json['startTime'],
                                         ["%Y-%m-%dT%H:%M:%S%z"])
    event_year = int(event_time.strftime("%Y"))
    if event_year < 2020 or event_year >= 2100:
        return False
    event_month_number = int(event_time.strftime("%m"))
    if event_month_number < 1 or event_month_number > 12:
        return False
    event_day_of_month = int(event_time.strftime("%d"))
    if event_day_of_month < 1 or event_day_of_month > 31:
        return False

    if event_json.get('name') and event_json.get('actor') and \
       event_json.get('uuid') and event_json.get('content'):
        if not _valid_uuid(event_json['uuid'], 4):
            return False
        print('Mobilizon type event')
        # if this is a full description of an event then save it
        # as a separate json file
        events_path = handle_dir + '/events'
        if not os.path.isdir(events_path):
            os.mkdir(events_path)
        events_year_path = \
            handle_dir + '/events/' + str(event_year)
        if not os.path.isdir(events_year_path):
            os.mkdir(events_year_path)
        event_id = str(event_year) + '-' + event_time.strftime("%m") + '-' + \
            event_time.strftime("%d") + '_' + event_json['uuid']
        event_filename = events_year_path + '/' + event_id + '.json'

        save_json(event_json, event_filename)
        # save to the events timeline
        tl_events_filename = handle_dir + '/events.txt'

        if os.path.isfile(tl_events_filename):
            _remove_event_from_timeline(event_id, tl_events_filename)
            try:
                with open(tl_events_filename, 'r+',
                          encoding='utf-8') as fp_tl_events:
                    content = fp_tl_events.read()
                    if event_id + '\n' not in content:
                        fp_tl_events.seek(0, 0)
                        fp_tl_events.write(event_id + '\n' + content)
            except OSError as ex:
                print('EX: Failed to write entry to events file ' +
                      tl_events_filename + ' ' + str(ex))
                return False
        else:
            try:
                with open(tl_events_filename, 'w+',
                          encoding='utf-8') as fp_tl_events:
                    fp_tl_events.write(event_id + '\n')
            except OSError:
                print('EX: save_event_post unable to write ' +
                      tl_events_filename)

    # create a directory for the calendar year
    if not os.path.isdir(calendar_path + '/' + str(event_year)):
        os.mkdir(calendar_path + '/' + str(event_year))

    # calendar month file containing event post Ids
    calendar_filename = calendar_path + '/' + str(event_year) + \
        '/' + str(event_month_number) + '.txt'

    # Does this event post already exist within the calendar month?
    if os.path.isfile(calendar_filename):
        if text_in_file(post_id, calendar_filename):
            # Event post already exists
            return False

    # append the post Id to the file for the calendar month
    try:
        with open(calendar_filename, 'a+', encoding='utf-8') as fp_calendar:
            fp_calendar.write(post_id + '\n')
    except OSError:
        print('EX: unable to append to calendar ' + calendar_filename)

    # create a file which will trigger a notification that
    # a new event has been added
    cal_notify_filename = handle_dir + '/.newCalendar'
    notify_str = \
        '/calendar?year=' + str(event_year) + '?month=' + \
        str(event_month_number) + '?day=' + str(event_day_of_month)
    try:
        with open(cal_notify_filename, 'w+', encoding='utf-8') as fp_cal:
            fp_cal.write(notify_str)
    except OSError:
        print('EX: save_event_post unable to write ' + cal_notify_filename)
        return False
    return True


def _is_happening_event(tag: {}) -> bool:
    """Is this tag an Event or Place ActivityStreams type?
    """
    if not tag.get('type'):
        return False
    if tag['type'] != 'Event' and tag['type'] != 'Place':
        return False
    return True


def _is_happening_post(post_json_object: {}) -> bool:
    """Is this a post with tags?
    """
    if not post_json_object:
        return False
    if not has_object_dict(post_json_object):
        return False
    if not post_json_object['object'].get('tag'):
        return False
    return True


def _event_text_match(content: str, text_match: str) -> bool:
    """Returns true of the content matches the search text
    """
    if not text_match:
        return True
    if '+' not in text_match:
        if text_match.strip().lower() in content.lower():
            return True
    else:
        match_list = text_match.split('+')
        for possible_match in match_list:
            if possible_match.strip().lower() in content.lower():
                return True
    return False


def _sort_todays_events(post_events_list: []) -> []:
    """Returns a list of events sorted in chronological order
    """
    post_events_dict = {}

    # convert the list to a dict indexed on time
    for post_event in post_events_list:
        for tag in post_event:
            # only check events (not places)
            if tag['type'] != 'Event':
                continue
            event_time = \
                date_from_string_format(tag['startTime'],
                                        ["%Y-%m-%dT%H:%M:%S%z"])
            post_events_dict[event_time] = post_event
            break

    # sort the dict
    new_post_events_list: list[list] = []
    sorted_events_dict = dict(sorted(post_events_dict.items()))
    for _, post_event in sorted_events_dict.items():
        new_post_events_list.append(post_event)
    return new_post_events_list


def get_todays_events(base_dir: str, nickname: str, domain: str,
                      curr_year: int, curr_month_number: int,
                      curr_day_of_month: int,
                      text_match: str, system_language: str) -> {}:
    """Retrieves calendar events for today
    Returns a dictionary of lists containing Event and Place activities
    """
    now = datetime.now()
    if not curr_year:
        year = now.year
    else:
        year = curr_year
    if not curr_month_number:
        month_number = now.month
    else:
        month_number = curr_month_number
    if not curr_day_of_month:
        day_number = now.day
    else:
        day_number = curr_day_of_month

    calendar_filename = \
        acct_dir(base_dir, nickname, domain) + \
        '/calendar/' + str(year) + '/' + str(month_number) + '.txt'
    events = {}
    if not os.path.isfile(calendar_filename):
        return events

    calendar_post_ids: list[str] = []
    recreate_events_file = False
    try:
        with open(calendar_filename, 'r', encoding='utf-8') as fp_events:
            for post_id in fp_events:
                post_id = remove_eol(post_id)
                post_filename = \
                    locate_post(base_dir, nickname, domain, post_id)
                if not post_filename:
                    recreate_events_file = True
                    continue

                post_json_object = load_json(post_filename)
                if not _is_happening_post(post_json_object):
                    continue

                content_language = system_language
                if post_json_object.get('object'):
                    content = None
                    if post_json_object['object'].get('contentMap'):
                        sys_lang = system_language
                        content_map = post_json_object['object']['contentMap']
                        if content_map.get(sys_lang):
                            content = content_map[sys_lang]
                            content_language = sys_lang
                    if not content:
                        if post_json_object['object'].get('content'):
                            content = post_json_object['object']['content']
                    if content:
                        if not _event_text_match(content, text_match):
                            continue

                public_event = is_public_post(post_json_object)

                post_event: list[dict] = []
                day_of_month = None
                for tag in post_json_object['object']['tag']:
                    if not _is_happening_event(tag):
                        continue

                    # this tag is an event or a place
                    if tag['type'] != 'Event':
                        # tag is a place
                        post_event.append(tag)
                        continue

                    # tag is an event
                    if not tag.get('startTime'):
                        continue

                    # is the tag for this day?
                    event_time = \
                        date_from_string_format(tag['startTime'],
                                                ["%Y-%m-%dT%H:%M:%S%z"])
                    event_year = int(event_time.strftime("%Y"))
                    event_month = int(event_time.strftime("%m"))
                    event_day = int(event_time.strftime("%d"))
                    if not (event_year == year and
                            event_month == month_number and
                            event_day == day_number):
                        continue

                    day_of_month = str(event_day)
                    if '#statuses#' in post_id:
                        # link to the id so that the event can be
                        # easily deleted
                        tag['post_id'] = post_id.split('#statuses#')[1]
                        tag['id'] = post_id.replace('#', '/')
                        tag['sender'] = post_id.split('#statuses#')[0]
                        tag['sender'] = tag['sender'].replace('#', '/')
                        tag['public'] = public_event
                        tag['language'] = content_language
                    post_event.append(tag)

                if not (post_event and day_of_month):
                    continue
                calendar_post_ids.append(post_id)
                if not events.get(day_of_month):
                    events[day_of_month]: list[dict] = []
                events[day_of_month].append(post_event)
                events[day_of_month] = \
                    _sort_todays_events(events[day_of_month])
    except OSError as exc:
        print('EX: get_todays_events failed to read ' +
              calendar_filename + ' ' + str(exc))

    # if some posts have been deleted then regenerate the calendar file
    if recreate_events_file:
        try:
            with open(calendar_filename, 'w+',
                      encoding='utf-8') as fp_calendar:
                for post_id in calendar_post_ids:
                    fp_calendar.write(post_id + '\n')
        except OSError:
            print('EX: unable to recreate events file 1 ' +
                  calendar_filename)

    return events


def _ical_date_string(date_str: str) -> str:
    """Returns an icalendar formatted date
    """
    replacements = {
        '-': '',
        ':': '',
        ' ': ''
    }
    date_str = replace_strings(date_str, replacements)
    return date_str


def _dav_encode_token(year: int, month_number: int,
                      message_id: str) -> str:
    """Returns a token corresponding to a calendar event
    """
    return str(year) + '_' + str(month_number) + '_' + \
        message_id.replace('/', '--').replace('#', '--')


def _icalendar_day(base_dir: str, nickname: str, domain: str,
                   day_events: [], person_cache: {}) -> str:
    """Returns a day's events in icalendar format
    """
    ical_str = ''
    print('icalendar: ' + str(day_events))
    for event_post in day_events:
        event_description = None
        event_place = None
        post_id = None
        sender_name = ''
        sender_actor = None
        event_is_public = False
        event_start = None
        event_end = None

        for evnt in event_post:
            if evnt['type'] == 'Event':
                if evnt.get('id'):
                    post_id = evnt['id']
                if evnt.get('startTime'):
                    event_start = \
                        date_from_string_format(evnt['startTime'],
                                                ["%Y-%m-%dT%H:%M:%S%z"])
                if evnt.get('endTime'):
                    event_end = \
                        date_from_string_format(evnt['startTime'],
                                                ["%Y-%m-%dT%H:%M:%S%z"])
                if 'public' in evnt:
                    if evnt['public'] is True:
                        event_is_public = True
                if evnt.get('sender'):
                    # get display name from sending actor
                    if evnt.get('sender'):
                        sender_actor = evnt['sender']
                        disp_name = \
                            get_display_name(base_dir, sender_actor,
                                             person_cache)
                        if disp_name:
                            sender_name = \
                                '<a href="' + sender_actor + '">' + \
                                disp_name + '</a>'
                if evnt.get('name'):
                    event_description = evnt['name'].strip()
            elif evnt['type'] == 'Place':
                if evnt.get('name'):
                    event_place = remove_html(evnt['name'])

        print('icalendar: ' + str(post_id) + ' ' +
              str(event_start) + ' ' + str(event_description) + ' ' +
              str(sender_actor))

        if not post_id or not event_start or not event_end or \
           not event_description or not sender_actor:
            continue

        # find the corresponding post
        post_filename = locate_post(base_dir, nickname, domain, post_id)
        if not post_filename:
            continue

        post_json_object = load_json(post_filename)
        if not post_json_object:
            continue

        # get the published date from the post
        if not post_json_object.get('object'):
            continue
        if not isinstance(post_json_object['object'], dict):
            continue
        if not post_json_object['object'].get('published'):
            continue
        if not isinstance(post_json_object['object']['published'], str):
            continue
        published = \
            _ical_date_string(post_json_object['object']['published'])

        event_start = \
            _ical_date_string(event_start.strftime("%Y-%m-%dT%H:%M:%SZ"))
        event_end = \
            _ical_date_string(event_end.strftime("%Y-%m-%dT%H:%M:%SZ"))

        token_year = int(event_start[:4])
        token_month_number = int(event_start[4:][:2])
        uid = _dav_encode_token(token_year, token_month_number, post_id)

        ical_str += \
            'BEGIN:VEVENT\n' + \
            'DTSTAMP:' + published + '\n' + \
            'UID:' + uid + '\n' + \
            'DTSTART:' + event_start + '\n' + \
            'DTEND:' + event_end + '\n' + \
            'STATUS:CONFIRMED\n'
        descr = remove_html(event_description)
        if len(descr) < 255:
            ical_str += \
                'SUMMARY:' + descr + '\n'
        else:
            ical_str += \
                'SUMMARY:' + descr[255:] + '\n'
            ical_str += \
                'DESCRIPTION:' + descr + '\n'
        if event_is_public:
            ical_str += \
                'CATEGORIES:APPOINTMENT,PUBLIC\n'
        else:
            ical_str += \
                'CATEGORIES:APPOINTMENT\n'
        if sender_name:
            ical_str += \
                'ORGANIZER;CN=' + remove_html(sender_name) + ':' + \
                sender_actor + '\n'
        else:
            ical_str += \
                'ORGANIZER:' + sender_actor + '\n'
        if event_place:
            ical_str += \
                'LOCATION:' + remove_html(event_place) + '\n'
        ical_str += 'END:VEVENT\n'
    return ical_str


def get_todays_events_icalendar(base_dir: str, nickname: str, domain: str,
                                year: int, month_number: int,
                                day_number: int, person_cache: {},
                                text_match: str, system_language: str) -> str:
    """Returns today's events in icalendar format
    """
    day_events = None
    events = \
        get_todays_events(base_dir, nickname, domain,
                          year, month_number, day_number,
                          text_match, system_language)
    if events:
        if events.get(str(day_number)):
            day_events = events[str(day_number)]

    ical_str = \
        'BEGIN:VCALENDAR\n' + \
        'PRODID:-//Fediverse//NONSGML Epicyon//EN\n' + \
        'VERSION:2.0\n'
    if not day_events:
        print('icalendar daily: ' + nickname + '@' + domain + ' ' +
              str(year) + '-' + str(month_number) +
              '-' + str(day_number) + ' ' + str(day_events))
        ical_str += 'END:VCALENDAR\n'
        return ical_str

    ical_str += \
        _icalendar_day(base_dir, nickname, domain, day_events, person_cache)

    ical_str += 'END:VCALENDAR\n'
    return ical_str


def get_month_events_icalendar(base_dir: str, nickname: str, domain: str,
                               year: int,
                               month_number: int,
                               person_cache: {},
                               text_match: str) -> str:
    """Returns today's events in icalendar format
    """
    only_show_reminders = False
    month_events = \
        get_calendar_events(base_dir, nickname, domain, year,
                            month_number, text_match,
                            only_show_reminders)

    ical_str = \
        'BEGIN:VCALENDAR\n' + \
        'PRODID:-//Fediverse//NONSGML Epicyon//EN\n' + \
        'VERSION:2.0\n'
    if not month_events:
        ical_str += 'END:VCALENDAR\n'
        return ical_str

    print('icalendar month: ' + str(month_events))
    for day_of_month in range(1, 32):
        if not month_events.get(str(day_of_month)):
            continue
        day_events = month_events[str(day_of_month)]
        ical_str += \
            _icalendar_day(base_dir, nickname, domain,
                           day_events, person_cache)

    ical_str += 'END:VCALENDAR\n'
    return ical_str


def day_events_check(base_dir: str, nickname: str, domain: str,
                     curr_date) -> bool:
    """Are there calendar events for the given date?
    """
    year = curr_date.year
    month_number = curr_date.month
    day_number = curr_date.day

    calendar_filename = \
        acct_dir(base_dir, nickname, domain) + \
        '/calendar/' + str(year) + '/' + str(month_number) + '.txt'
    if not os.path.isfile(calendar_filename):
        return False

    events_exist = False
    try:
        with open(calendar_filename, 'r', encoding='utf-8') as fp_events:
            for post_id in fp_events:
                post_id = remove_eol(post_id)
                post_filename = \
                    locate_post(base_dir, nickname, domain, post_id)
                if not post_filename:
                    continue

                post_json_object = load_json(post_filename)
                if not _is_happening_post(post_json_object):
                    continue

                for tag in post_json_object['object']['tag']:
                    if not _is_happening_event(tag):
                        continue
                    # this tag is an event or a place
                    if tag['type'] != 'Event':
                        continue
                    # tag is an event
                    if not tag.get('startTime'):
                        continue
                    event_time = \
                        date_from_string_format(tag['startTime'],
                                                ["%Y-%m-%dT%H:%M:%S%z"])
                    if int(event_time.strftime("%d")) != day_number:
                        continue
                    if int(event_time.strftime("%m")) != month_number:
                        continue
                    if int(event_time.strftime("%Y")) != year:
                        continue
                    events_exist = True
                    break
    except OSError:
        print('EX: day_events_check failed to read ' + calendar_filename)

    return events_exist


def get_this_weeks_events(base_dir: str, nickname: str, domain: str) -> {}:
    """Retrieves calendar events for this week
    Returns a dictionary indexed by day number of lists containing
    Event and Place activities
    Note: currently not used but could be with a weekly calendar screen
    """
    now = datetime.now()
    end_of_week = now + timedelta(7)
    year = now.year
    month_number = now.month

    calendar_filename = \
        acct_dir(base_dir, nickname, domain) + \
        '/calendar/' + str(year) + '/' + str(month_number) + '.txt'

    events = {}
    if not os.path.isfile(calendar_filename):
        return events

    calendar_post_ids: list[str] = []
    recreate_events_file = False
    try:
        with open(calendar_filename, 'r', encoding='utf-8') as fp_events:
            for post_id in fp_events:
                post_id = remove_eol(post_id)
                post_filename = \
                    locate_post(base_dir, nickname, domain, post_id)
                if not post_filename:
                    recreate_events_file = True
                    continue

                post_json_object = load_json(post_filename)
                if not _is_happening_post(post_json_object):
                    continue

                post_event: list[dict] = []
                week_day_index = None
                for tag in post_json_object['object']['tag']:
                    if not _is_happening_event(tag):
                        continue

                    # this tag is an event or a place
                    if tag['type'] != 'Event':
                        # tag is a place
                        post_event.append(tag)
                        continue

                    # tag is an event
                    if not tag.get('startTime'):
                        continue
                    event_time = \
                        date_from_string_format(tag['startTime'],
                                                ["%Y-%m-%dT%H:%M:%S%z"])
                    if now <= event_time <= end_of_week:
                        week_day_index = (event_time - now).days()
                        post_event.append(tag)

                if not (post_event and week_day_index):
                    continue
                calendar_post_ids.append(post_id)
                if not events.get(week_day_index):
                    events[week_day_index]: list[dict] = []
                events[week_day_index].append(post_event)
    except OSError:
        print('EX: get_this_weeks_events failed to read ' + calendar_filename)

    # if some posts have been deleted then regenerate the calendar file
    if recreate_events_file:
        try:
            with open(calendar_filename, 'w+',
                      encoding='utf-8') as fp_calendar:
                for post_id in calendar_post_ids:
                    fp_calendar.write(post_id + '\n')
        except OSError:
            print('EX: unable to recreate events file 2 ' +
                  calendar_filename)

    return events


def get_calendar_events(base_dir: str, nickname: str, domain: str,
                        year: int, month_number: int,
                        text_match: str,
                        only_show_reminders: bool) -> {}:
    """Retrieves calendar events
    Returns a dictionary indexed by day number of lists containing
    Event and Place activities
    """
    calendar_filename = \
        acct_dir(base_dir, nickname, domain) + \
        '/calendar/' + str(year) + '/' + str(month_number) + '.txt'

    events = {}
    if not os.path.isfile(calendar_filename):
        return events

    calendar_post_ids: list[str] = []
    recreate_events_file = False
    try:
        with open(calendar_filename, 'r', encoding='utf-8') as fp_events:
            for post_id in fp_events:
                post_id = remove_eol(post_id)
                post_filename = \
                    locate_post(base_dir, nickname, domain, post_id)
                if not post_filename:
                    recreate_events_file = True
                    continue

                post_json_object = load_json(post_filename)
                if not post_json_object:
                    continue
                if not _is_happening_post(post_json_object):
                    continue
                if only_show_reminders:
                    if not is_reminder(post_json_object):
                        continue

                if post_json_object.get('object'):
                    if post_json_object['object'].get('content'):
                        content = post_json_object['object']['content']
                        if not _event_text_match(content, text_match):
                            continue

                post_event: list[dict] = []
                day_of_month = None
                for tag in post_json_object['object']['tag']:
                    if not _is_happening_event(tag):
                        continue

                    # this tag is an event or a place
                    if tag['type'] != 'Event':
                        # tag is a place
                        post_event.append(tag)
                        continue

                    # tag is an event
                    if not tag.get('startTime'):
                        continue

                    # is the tag for this month?
                    event_time = \
                        date_from_string_format(tag['startTime'],
                                                ["%Y-%m-%dT%H:%M:%S%z"])
                    event_year = int(event_time.strftime("%Y"))
                    event_month = int(event_time.strftime("%m"))
                    if not (event_year == year and
                            event_month == month_number):
                        continue

                    event_day = int(event_time.strftime("%d"))
                    day_of_month = str(event_day)
                    if '#statuses#' in post_id:
                        tag['post_id'] = post_id.split('#statuses#')[1]
                        tag['id'] = post_id.replace('#', '/')
                        tag['sender'] = post_id.split('#statuses#')[0]
                        tag['sender'] = tag['sender'].replace('#', '/')
                    post_event.append(tag)

                if not (post_event and day_of_month):
                    continue
                calendar_post_ids.append(post_id)
                if not events.get(day_of_month):
                    events[day_of_month]: list[dict] = []
                events[day_of_month].append(post_event)
    except OSError:
        print('EX: get_calendar_events failed to read ' + calendar_filename)

    # if some posts have been deleted then regenerate the calendar file
    if recreate_events_file:
        try:
            with open(calendar_filename, 'w+',
                      encoding='utf-8') as fp_calendar:
                for post_id in calendar_post_ids:
                    fp_calendar.write(post_id + '\n')
        except OSError:
            print('EX: unable to recreate events file 3 ' +
                  calendar_filename)

    return events


def remove_calendar_event(base_dir: str, nickname: str, domain: str,
                          year: int, month_number: int,
                          message_id: str) -> None:
    """Removes a calendar event
    """
    calendar_filename = \
        acct_dir(base_dir, nickname, domain) + \
        '/calendar/' + str(year) + '/' + str(month_number) + '.txt'
    if not os.path.isfile(calendar_filename):
        return
    if '/' in message_id:
        message_id = message_id.replace('/', '#')
    if not text_in_file(message_id, calendar_filename):
        message_id = message_id.replace('#', '/')
        if not text_in_file(message_id, calendar_filename):
            return
    lines_str = ''
    try:
        with open(calendar_filename, 'r', encoding='utf-8') as fp_cal:
            lines_str = fp_cal.read()
    except OSError:
        print('EX: remove_calendar_event unable to read calendar file ' +
              calendar_filename)
    if not lines_str:
        return
    lines = lines_str.split('\n')
    print('Removing calendar event: ' + message_id)
    try:
        with open(calendar_filename, 'w+', encoding='utf-8') as fp_cal:
            for line in lines:
                if message_id in line:
                    continue
                fp_cal.write(line + '\n')
    except OSError:
        print('EX: unable to remove calendar event ' +
              calendar_filename)


def _dav_decode_token(token: str) -> (int, int, str):
    """Decodes a token corresponding to a calendar event
    """
    if '_' not in token or '--' not in token:
        return None, None, None
    token_sections = token.split('_')
    if len(token_sections) != 3:
        return None, None, None
    if not token_sections[0].isdigit():
        return None, None, None
    if not token_sections[1].isdigit():
        return None, None, None
    token_year = int(token_sections[0])
    token_month_number = int(token_sections[1])
    token_post_id = token_sections[2].replace('--', '/')
    return token_year, token_month_number, token_post_id


def dav_propfind_response(nickname: str, xml_str: str) -> str:
    """Returns the response to caldav PROPFIND
    """
    if '<d:propfind' not in xml_str or \
       '</d:propfind>' not in xml_str:
        return None
    response_str = \
        '<d:multistatus xmlns:d="DAV:" ' + \
        'xmlns:cs="http://calendarserver.org/ns/">\n' + \
        '    <d:response>\n' + \
        '        <d:href>/calendars/' + nickname + '/</d:href>\n' + \
        '        <d:propstat>\n' + \
        '            <d:prop>\n' + \
        '                <d:displayname />\n' + \
        '                <cs:getctag />\n' + \
        '            </d:prop>\n' + \
        '            <d:status>HTTP/1.1 200 OK</d:status>\n' + \
        '        </d:propstat>\n' + \
        '    </d:response>\n' + \
        '</d:multistatus>'
    return response_str


def _dav_store_event(base_dir: str, nickname: str, domain: str,
                     event_list: [], http_prefix: str,
                     system_language: str) -> bool:
    """Stores a calendar event obtained via caldav PUT
    """
    event_str = str(event_list)
    if 'DTSTAMP:' not in event_str or \
       'DTSTART:' not in event_str or \
       'DTEND:' not in event_str:
        return False
    if 'STATUS:' not in event_str and 'DESCRIPTION:' not in event_str:
        return False

    timestamp = None
    start_time = None
    end_time = None
    description = None
    for line in event_list:
        if line.startswith('DTSTAMP:'):
            timestamp = line.split(':', 1)[1]
        elif line.startswith('DTSTART:'):
            start_time = line.split(':', 1)[1]
        elif line.startswith('DTEND:'):
            end_time = line.split(':', 1)[1]
        elif line.startswith('SUMMARY:') or line.startswith('DESCRIPTION:'):
            description = line.split(':', 1)[1]
        elif line.startswith('LOCATION:'):
            location = line.split(':', 1)[1]

    if not timestamp or \
       not start_time or \
       not end_time or \
       not description:
        return False
    if len(timestamp) < 15:
        return False
    if len(start_time) < 15:
        return False
    if len(end_time) < 15:
        return False

    # check that the description is valid
    if is_filtered(base_dir, nickname, domain, description,
                   system_language):
        return False

    # convert to the expected time format
    published = _dav_date_from_string(timestamp)
    if not published:
        return False
    start_time = _dav_date_from_string(start_time)
    if not start_time:
        return False
    end_time = _dav_date_from_string(end_time)
    if not end_time:
        return False

    post_id = ''
    post_context = get_individual_post_context()
    # create the status number from DTSTAMP
    status_number, published = get_status_number(published)
    # get the post id
    actor = http_prefix + "://" + domain + "/users/" + nickname
    actor2 = http_prefix + "://" + domain + "/@" + nickname
    post_id = actor + "/statuses/" + status_number

    next_str = post_id + "/replies?only_other_accounts=true&page=true"
    content = \
        '<p><span class=\"h-card\"><a href=\"' + actor2 + \
        '\" class=\"u-url mention\" tabindex="10">@<span>' + nickname + \
        '</span></a></span>' + remove_html(description) + '</p>'
    event_json = {
        '@context': post_context,
        'id': post_id + '/activity',
        'type': 'Create',
        'actor': actor,
        'published': published,
        'to': [actor],
        'cc': [],
        'object': {
            'id': post_id,
            'conversation': post_id_to_convthread_id(post_id, published),
            'context': post_id,
            'type': "Note",
            'summary': None,
            'inReplyTo': None,
            'published': published,
            'url': actor + '/' + status_number,
            'attributedTo': actor,
            'to': [actor],
            'cc': [],
            'sensitive': False,
            'atomUri': post_id,
            'inReplyToAtomUri': None,
            'commentsEnabled': False,
            'rejectReplies': True,
            'mediaType': 'text/html',
            'content': content,
            'contentMap': {
                system_language: content
            },
            'attachment': [],
            'tag': [
                {
                    'href': actor2,
                    'name': '@' + nickname + '@' + domain,
                    'type': 'Mention'
                },
                {
                    '@context': [
                        'https://www.w3.org/ns/activitystreams',
                        'https://w3id.org/security/v1'
                    ],
                    'type': 'Event',
                    'name': content,
                    'startTime': start_time,
                    'endTime': end_time
                }
            ],
            'replies': {
                'id': post_id + '/replies',
                'repliesOf': post_id,
                'type': 'Collection',
                'first': {
                    'type': 'CollectionPage',
                    'next': next_str,
                    'partOf': post_id + '/replies',
                    'items': []
                }
            }
        }
    }
    if location:
        event_json['object']['tag'].append({
            '@context': [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1'
            ],
            'type': 'Place',
            'name': location
        })
        event_json['object']['location'] = {
            'type': 'Place',
            'name': location
        }
    handle = nickname + '@' + domain
    handle_dir = acct_handle_dir(base_dir, handle)
    outbox_dir = handle_dir + '/outbox'
    if not os.path.isdir(outbox_dir):
        return False
    filename = outbox_dir + '/' + post_id.replace('/', '#') + '.json'
    save_json(event_json, filename)
    save_event_post(base_dir, handle, post_id, event_json)

    return True


def _dav_update_recent_etags(etag: str, nickname: str,
                             recent_dav_etags: {}) -> None:
    """Updates the recent etags for each account
    """
    # update the recent caldav etags for each account
    if not recent_dav_etags.get(nickname):
        recent_dav_etags[nickname] = [etag]
    else:
        # only keep a limited number of recent etags
        while len(recent_dav_etags[nickname]) > 32:
            recent_dav_etags[nickname].pop(0)
        # append the etag to the recent list
        if etag not in recent_dav_etags[nickname]:
            recent_dav_etags[nickname].append(etag)


def dav_put_response(base_dir: str, nickname: str, domain: str,
                     xml_str: str, http_prefix: str,
                     system_language: str,
                     recent_dav_etags: {}) -> str:
    """Returns the response to caldav PUT
    """
    if '\n' not in xml_str:
        return None
    if 'BEGIN:VCALENDAR' not in xml_str or \
       'END:VCALENDAR' not in xml_str:
        return None
    if 'BEGIN:VEVENT' not in xml_str or \
       'END:VEVENT' not in xml_str:
        return None

    etag = md5(xml_str.encode('utf-8')).hexdigest()
    if recent_dav_etags.get(nickname):
        if etag in recent_dav_etags[nickname]:
            return 'Not modified'

    stored_count = 0
    reading_event = False
    lines_list = xml_str.split('\n')
    event_list: list[dict] = []
    for line in lines_list:
        line = line.strip()
        if not reading_event:
            if line == 'BEGIN:VEVENT':
                reading_event = True
                event_list: list[dict] = []
        else:
            if line == 'END:VEVENT':
                if event_list:
                    _dav_store_event(base_dir, nickname, domain,
                                     event_list, http_prefix,
                                     system_language)
                    stored_count += 1
                reading_event = False
            else:
                event_list.append(line)
    if stored_count == 0:
        return None
    _dav_update_recent_etags(etag, nickname, recent_dav_etags)
    return 'ETag:' + etag


def dav_report_response(base_dir: str, nickname: str, domain: str,
                        xml_str: str,
                        person_cache: {}, http_prefix: str,
                        curr_etag: str, recent_dav_etags: {},
                        domain_full: str, system_language: str) -> str:
    """Returns the response to caldav REPORT
    """
    if '<c:calendar-query' not in xml_str or \
       '</c:calendar-query>' not in xml_str:
        if '<c:calendar-multiget' not in xml_str or \
           '</c:calendar-multiget>' not in xml_str:
            return None

    if curr_etag:
        if recent_dav_etags.get(nickname):
            if curr_etag in recent_dav_etags[nickname]:
                return "Not modified"

    xml_str_lower = xml_str.lower()
    query_start_time = None
    query_end_time = None
    if ':time-range' in xml_str_lower:
        time_range_str = xml_str_lower.split(':time-range')[1]
        if 'start=' in time_range_str and 'end=' in time_range_str:
            start_time_str = time_range_str.split('start=')[1]
            if start_time_str.startswith("'"):
                query_start_time_str = start_time_str.split("'")[1]
                query_start_time = _dav_date_from_string(query_start_time_str)
            elif start_time_str.startswith('"'):
                query_start_time_str = start_time_str.split('"')[1]
                query_start_time = _dav_date_from_string(query_start_time_str)

            end_time_str = time_range_str.split('end=')[1]
            if end_time_str.startswith("'"):
                query_end_time_str = end_time_str.split("'")[1]
                query_end_time = _dav_date_from_string(query_end_time_str)
            elif end_time_str.startswith('"'):
                query_end_time_str = end_time_str.split('"')[1]
                query_end_time = _dav_date_from_string(query_end_time_str)

    text_match = ''
    if ':text-match' in xml_str_lower:
        match_str = xml_str_lower.split(':text-match')[1]
        if '>' in match_str and '<' in match_str:
            text_match = match_str.split('>')[1]
            if '<' in text_match:
                text_match = text_match.split('<')[0]
            else:
                text_match = ''

    ical_events = None
    etag = None
    events_href = ''
    responses = ''
    search_date = datetime.now()
    if query_start_time and query_end_time:
        query_start_year = int(query_start_time.split('-')[0])
        query_start_month = int(query_start_time.split('-')[1])
        query_start_day = query_start_time.split('-')[2]
        query_start_day = int(query_start_day.split('T')[0])
        query_end_year = int(query_end_time.split('-')[0])
        query_end_month = int(query_end_time.split('-')[1])
        query_end_day = query_end_time.split('-')[2]
        query_end_day = int(query_end_day.split('T')[0])
        if query_start_year == query_end_year and \
           query_start_month == query_end_month:
            if query_start_day == query_end_day:
                # calendar for one day
                search_date = \
                    date_from_numbers(query_start_year, query_start_month,
                                      query_start_day, 0, 0)
                ical_events = \
                    get_todays_events_icalendar(base_dir, nickname, domain,
                                                search_date.year,
                                                search_date.month,
                                                search_date.day, person_cache,
                                                text_match,
                                                system_language)
                events_href = \
                    http_prefix + '://' + domain_full + '/users/' + \
                    nickname + '/calendar?year=' + \
                    str(search_date.year) + '?month=' + \
                    str(search_date.month) + '?day=' + str(search_date.day)
                if ical_events:
                    if 'VEVENT' in ical_events:
                        ical_events_encoded = ical_events.encode('utf-8')
                        etag = md5(ical_events_encoded).hexdigest()
                        responses = \
                            '    <d:response>\n' + \
                            '        <d:href>' + events_href + \
                            '</d:href>\n' + \
                            '        <d:propstat>\n' + \
                            '            <d:prop>\n' + \
                            '                <d:getetag>"' + \
                            etag + '"</d:getetag>\n' + \
                            '                <c:calendar-data>' + \
                            ical_events + \
                            '                </c:calendar-data>\n' + \
                            '            </d:prop>\n' + \
                            '            <d:status>HTTP/1.1 200 OK' + \
                            '</d:status>\n' + \
                            '        </d:propstat>\n' + \
                            '    </d:response>\n'
            elif query_start_day == 1 and query_start_day >= 28:
                # calendar for a month
                ical_events = \
                    get_month_events_icalendar(base_dir, nickname, domain,
                                               query_start_year,
                                               query_start_month,
                                               person_cache,
                                               text_match)
                events_href = \
                    http_prefix + '://' + domain_full + '/users/' + \
                    nickname + '/calendar?year=' + \
                    str(query_start_year) + '?month=' + \
                    str(query_start_month)
                if ical_events:
                    if 'VEVENT' in ical_events:
                        ical_events_encoded = ical_events.encode('utf-8')
                        etag = md5(ical_events_encoded).hexdigest()
                        responses = \
                            '    <d:response>\n' + \
                            '        <d:href>' + events_href + \
                            '</d:href>\n' + \
                            '        <d:propstat>\n' + \
                            '            <d:prop>\n' + \
                            '                <d:getetag>"' + \
                            etag + '"</d:getetag>\n' + \
                            '                <c:calendar-data>' + \
                            ical_events + \
                            '                </c:calendar-data>\n' + \
                            '            </d:prop>\n' + \
                            '            <d:status>HTTP/1.1 200 OK' + \
                            '</d:status>\n' + \
                            '        </d:propstat>\n' + \
                            '    </d:response>\n'
        if not responses:
            all_events = ''
            for year in range(query_start_year, query_end_year+1):
                if query_start_year == query_end_year:
                    start_month_number = query_start_month
                    end_month_number = query_end_month
                elif year == query_end_year:
                    start_month_number = 1
                    end_month_number = query_end_month
                elif year == query_start_year:
                    start_month_number = query_start_month
                    end_month_number = 12
                else:
                    start_month_number = 1
                    end_month_number = 12
                for month in range(start_month_number, end_month_number+1):
                    ical_events = \
                        get_month_events_icalendar(base_dir,
                                                   nickname, domain,
                                                   year, month,
                                                   person_cache,
                                                   text_match)
                    events_href = \
                        http_prefix + '://' + domain_full + '/users/' + \
                        nickname + '/calendar?year=' + \
                        str(year) + '?month=' + \
                        str(month)
                    if ical_events:
                        if 'VEVENT' in ical_events:
                            all_events += ical_events
                            ical_events_encoded = ical_events.encode('utf-8')
                            local_etag = md5(ical_events_encoded).hexdigest()
                            responses += \
                                '    <d:response>\n' + \
                                '        <d:href>' + events_href + \
                                '</d:href>\n' + \
                                '        <d:propstat>\n' + \
                                '            <d:prop>\n' + \
                                '                <d:getetag>"' + \
                                local_etag + '"</d:getetag>\n' + \
                                '                <c:calendar-data>' + \
                                ical_events + \
                                '                </c:calendar-data>\n' + \
                                '            </d:prop>\n' + \
                                '            <d:status>HTTP/1.1 200 OK' + \
                                '</d:status>\n' + \
                                '        </d:propstat>\n' + \
                                '    </d:response>\n'
            ical_events_encoded = all_events.encode('utf-8')
            etag = md5(ical_events_encoded).hexdigest()

    # today's calendar events
    if not ical_events:
        ical_events = \
            get_todays_events_icalendar(base_dir, nickname, domain,
                                        search_date.year, search_date.month,
                                        search_date.day, person_cache,
                                        text_match,
                                        system_language)
        events_href = \
            http_prefix + '://' + domain_full + '/users/' + \
            nickname + '/calendar?year=' + \
            str(search_date.year) + '?month=' + \
            str(search_date.month) + '?day=' + str(search_date.day)
        if ical_events:
            if 'VEVENT' in ical_events:
                ical_events_encoded = ical_events.encode('utf-8')
                etag = md5(ical_events_encoded).hexdigest()
                responses = \
                    '    <d:response>\n' + \
                    '        <d:href>' + events_href + '</d:href>\n' + \
                    '        <d:propstat>\n' + \
                    '            <d:prop>\n' + \
                    '                <d:getetag>"' + etag + \
                    '"</d:getetag>\n' + \
                    '                <c:calendar-data>' + ical_events + \
                    '                </c:calendar-data>\n' + \
                    '            </d:prop>\n' + \
                    '            <d:status>HTTP/1.1 200 OK</d:status>\n' + \
                    '        </d:propstat>\n' + \
                    '    </d:response>\n'

    if not ical_events or not etag:
        return None
    if 'VEVENT' not in ical_events:
        return None

    if etag == curr_etag:
        return "Not modified"
    response_str = \
        '<?xml version="1.0" encoding="utf-8" ?>\n' + \
        '<d:multistatus xmlns:d="DAV:" ' + \
        'xmlns:cs="http://calendarserver.org/ns/">\n' + \
        responses + '</d:multistatus>'

    _dav_update_recent_etags(etag, nickname, recent_dav_etags)
    return response_str


def dav_delete_response(base_dir: str, nickname: str, domain: str,
                        path: str, http_prefix: str, debug: bool,
                        recent_posts_cache: {}) -> str:
    """Returns the response to caldav DELETE
    """
    token = path.split('/calendars/' + nickname + '/')[1]
    token_year, token_month_number, token_post_id = \
        _dav_decode_token(token)
    if not token_year:
        return None
    post_filename = locate_post(base_dir, nickname, domain, token_post_id)
    if not post_filename:
        print('Calendar post not found ' + token_post_id)
        return None
    post_json_object = load_json(post_filename)
    if not _is_happening_post(post_json_object):
        print(token_post_id + ' is not a calendar post')
        return None
    remove_calendar_event(base_dir, nickname, domain,
                          token_year, token_month_number,
                          token_post_id)
    delete_post(base_dir, http_prefix,
                nickname, domain, post_filename,
                debug, recent_posts_cache, True)
    return 'Ok'


def dav_month_via_server(session, http_prefix: str,
                         nickname: str, domain: str, port: int,
                         debug: bool,
                         year: int, month: int,
                         password: str) -> str:
    """Gets the icalendar for a month via caldav
    """
    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'application/xml',
        'Authorization': auth_header
    }
    domain_full = get_full_domain(domain, port)
    params = {}
    url = http_prefix + '://' + domain_full + '/calendars/' + nickname
    month_str = str(month)
    if month < 10:
        month_str = '0' + month_str
    xml_str = \
        '<?xml version="1.0" encoding="utf-8" ?>\n' + \
        '<c:calendar-query xmlns:d="DAV:"\n' + \
        '                  xmlns:c="urn:ietf:params:xml:ns:caldav">\n' + \
        '  <d:prop>\n' + \
        '    <d:getetag/>\n' + \
        '  </d:prop>\n' + \
        '  <c:filter>\n' + \
        '    <c:comp-filter name="VCALENDAR">\n' + \
        '      <c:comp-filter name="VEVENT">\n' + \
        '      <c:time-range start="' + str(year) + month_str + \
        '01T000000Z"\n' + \
        '                    end="' + str(year) + month_str + \
        '31T235959Z"/>\n' + \
        '      </c:comp-filter>\n' + \
        '    </c:comp-filter>\n' + \
        '  </c:filter>\n' + \
        '</c:calendar-query>'
    result = \
        get_method("REPORT", xml_str, session, url, params, headers, debug,
                   __version__, http_prefix, domain)
    return result


def dav_day_via_server(session, http_prefix: str,
                       nickname: str, domain: str, port: int,
                       debug: bool,
                       year: int, month: int, day: int,
                       password: str) -> str:
    """Gets the icalendar for a day via caldav
    """
    auth_header = create_basic_auth_header(nickname, password)

    headers = {
        'host': domain,
        'Content-type': 'application/xml',
        'Authorization': auth_header
    }
    domain_full = get_full_domain(domain, port)
    params = {}
    url = http_prefix + '://' + domain_full + '/calendars/' + nickname
    month_str = str(month)
    if month < 10:
        month_str = '0' + month_str
    day_str = str(day)
    if day < 10:
        day_str = '0' + day_str
    xml_str = \
        '<?xml version="1.0" encoding="utf-8" ?>\n' + \
        '<c:calendar-query xmlns:d="DAV:"\n' + \
        '                  xmlns:c="urn:ietf:params:xml:ns:caldav">\n' + \
        '  <d:prop>\n' + \
        '    <d:getetag/>\n' + \
        '  </d:prop>\n' + \
        '  <c:filter>\n' + \
        '    <c:comp-filter name="VCALENDAR">\n' + \
        '      <c:comp-filter name="VEVENT">\n' + \
        '      <c:time-range start="' + str(year) + month_str + \
        day_str + 'T000000Z"\n' + \
        '                    end="' + str(year) + month_str + \
        day_str + 'T235959Z"/>\n' + \
        '      </c:comp-filter>\n' + \
        '    </c:comp-filter>\n' + \
        '  </c:filter>\n' + \
        '</c:calendar-query>'
    result = \
        get_method("REPORT", xml_str, session, url, params, headers, debug,
                   __version__, http_prefix, domain)
    return result
