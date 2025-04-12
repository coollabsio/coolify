__filename__ = "reading.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Core"


import os
from collections import OrderedDict
from utils import data_dir
from utils import get_post_attachments
from utils import get_content_from_post
from utils import has_object_dict
from utils import remove_id_ending
from utils import get_attributed_to
from utils import load_json
from utils import save_json
from utils import remove_html
from utils import get_image_extensions
from utils import date_epoch
from utils import date_from_string_format


def get_book_link_from_content(content: str) -> str:
    """ Returns a book link from the given content
    """
    if '://' not in content or \
       '"' not in content:
        return None
    sections = content.split('://')
    if '"' not in sections[0] or '"' not in sections[1]:
        return None
    previous_str = sections[0].split('"')[-1]
    next_str = sections[1].split('"')[0]
    book_url = previous_str + '://' + next_str
    return book_url


def get_book_from_post(post_json_object: {}, debug: bool) -> {}:
    """ Returns a book details from the given post
    """
    if 'tag' not in post_json_object:
        if debug:
            print('DEBUG: get_book_from_post no tag in post')
        return {}
    if not isinstance(post_json_object['tag'], list):
        if debug:
            print('DEBUG: get_book_from_post tag is not a list')
        return {}
    for tag_dict in post_json_object['tag']:
        if 'type' not in tag_dict:
            continue
        if not isinstance(tag_dict['type'], str):
            continue
        if tag_dict['type'] != 'Edition':
            continue
        if not tag_dict.get('href'):
            continue
        if not isinstance(tag_dict['href'], str):
            continue
        if not tag_dict.get('name'):
            continue
        if not isinstance(tag_dict['name'], str):
            continue
        tag_dict['name'] = tag_dict['name'].replace('@', '')
        return tag_dict.copy()
    return {}


def _get_book_image_from_post(post_json_object: {}) -> str:
    """ Returns a book image from the given post
    """
    post_attachments = get_post_attachments(post_json_object)
    if not post_attachments:
        return ''
    extensions = get_image_extensions()
    for attach_dict in post_attachments:
        if not isinstance(attach_dict, dict):
            continue
        if 'url' not in attach_dict:
            continue
        if not isinstance(attach_dict['url'], str):
            continue
        for ext in extensions:
            if attach_dict['url'].endswith('.' + ext):
                return attach_dict['url']
    return ''


def has_edition_tag(post_json_object: {}) -> bool:
    """Checks whether the given post has an Edition tag
    indicating that it contains a book event
    """
    post_obj = post_json_object
    if has_object_dict(post_json_object):
        post_obj = post_json_object['object']

    if not post_obj.get('tag'):
        return False
    if not isinstance(post_obj['tag'], list):
        return False
    for tag in post_obj['tag']:
        if not isinstance(tag, dict):
            continue
        if not tag.get('type'):
            continue
        if not isinstance(tag['type'], str):
            continue
        if tag['type'] == 'Edition':
            return True
    return False


def get_reading_status(post_json_object: {},
                       system_language: str,
                       languages_understood: [],
                       translate: {},
                       debug: bool) -> {}:
    """Returns any reading status from the content of a post
    """
    post_obj = post_json_object
    if has_object_dict(post_json_object):
        post_obj = post_json_object['object']

    content = get_content_from_post(post_json_object, system_language,
                                    languages_understood,
                                    "content")
    if not content:
        if debug:
            print('DEBUG: get_reading_status no content')
        return {}
    book_url = get_book_link_from_content(content)
    if not book_url:
        if debug:
            print('DEBUG: get_reading_status no book url')
        return {}

    if not post_obj.get('id'):
        if debug:
            print('DEBUG: get_reading_status no id')
        return {}
    if not isinstance(post_obj['id'], str):
        if debug:
            print('DEBUG: get_reading_status id is not a string')
        return {}

    # get the published date
    if not post_obj.get('published'):
        if debug:
            print('DEBUG: get_reading_status no published')
        return {}
    if not isinstance(post_obj['published'], str):
        if debug:
            print('DEBUG: get_reading_status published is not a string')
        return {}
    published = post_obj['published']
    if post_obj.get('updated'):
        if isinstance(post_obj['updated'], str):
            published = post_obj['updated']

    if not post_obj.get('attributedTo'):
        if debug:
            print('DEBUG: get_reading_status no attributedTo')
        return {}
    actor = get_attributed_to(post_obj['attributedTo'])
    if not actor:
        if debug:
            print('DEBUG: get_reading_status no actor')
        return {}

    book_image_url = _get_book_image_from_post(post_obj)

    # rating of a book
    if post_obj.get('rating'):
        rating = post_obj['rating']
        if isinstance(rating, (float, int)):
            translated_str = 'rated'
            if translate.get('rated'):
                translated_str = translate['rated']
            if translated_str in content or \
               'rated' in content:
                book_dict = {
                    'id': remove_id_ending(post_obj['id']),
                    'actor': actor,
                    'type': 'rated',
                    'href': book_url,
                    'rating': rating,
                    'published': published
                }
                if book_image_url:
                    book_dict['image_url'] = book_image_url
                return book_dict

    if not has_edition_tag(post_json_object):
        return {}

    # get the book details from a post tag
    book_dict = get_book_from_post(post_obj, debug)
    if not book_dict:
        if debug:
            print('DEBUG: get_reading_status no book_dict ' +
                  str(post_json_object))
        return {}

    # want to read a book
    translated_str = 'wants to read'
    if translate.get('wants to read'):
        translated_str = translate['wants to read']
    if translated_str in content or \
       'wants to read' in content:
        book_dict['id'] = remove_id_ending(post_obj['id'])
        book_dict['actor'] = actor
        book_dict['type'] = 'want'
        book_dict['published'] = published
        if book_image_url:
            book_dict['image_url'] = book_image_url
        return book_dict

    translated_str = 'finished reading'
    if translate.get('finished reading'):
        translated_str = translate['finished reading']
    if translated_str in content or \
       'finished reading' in content:
        book_dict['id'] = remove_id_ending(post_obj['id'])
        book_dict['actor'] = actor
        book_dict['type'] = 'finished'
        book_dict['published'] = published
        if book_image_url:
            book_dict['image_url'] = book_image_url
        return book_dict

    translated_str = 'am reading'
    if translate.get('am reading'):
        translated_str = translate['am reading']
    if translated_str in content or \
       'am reading' in content or \
       'currently reading' in content or \
       'is reading' in content:
        book_dict['id'] = remove_id_ending(post_obj['id'])
        book_dict['actor'] = actor
        book_dict['type'] = 'reading'
        book_dict['published'] = published
        if book_image_url:
            book_dict['image_url'] = book_image_url
        return book_dict

    return {}


def remove_reading_event(base_dir: str,
                         actor: str, post_secs_since_epoch: str,
                         book_event_type: str,
                         books_cache: {},
                         debug: bool) -> bool:
    """Removes a reading status for the given actor
    """
    if not book_event_type:
        print('remove_reading_event no book event')
        return False
    reading_path = data_dir(base_dir) + '/reading'
    readers_path = reading_path + '/readers'
    reader_books_filename = \
        readers_path + '/' + actor.replace('/', '#') + '.json'

    reader_books_json = {}
    if 'readers' not in books_cache:
        books_cache['readers'] = {}
    if books_cache['readers'].get(actor):
        reader_books_json = books_cache['readers'][actor]
    elif os.path.isfile(reader_books_filename):
        # if not in cache then load from file
        reader_books_json = load_json(reader_books_filename)
    if not reader_books_json:
        if debug:
            print('remove_reading_event reader_books_json does not exist')
        return False
    if not reader_books_json.get('timeline'):
        if debug:
            print('remove_reading_event ' +
                  'reader_books_json timeline does not exist')
        return False
    if not reader_books_json['timeline'].get(post_secs_since_epoch):
        if debug:
            print('remove_reading_event ' +
                  'reader_books_json timeline event does not exist ' +
                  str(post_secs_since_epoch))
        return False
    book_url = reader_books_json['timeline'][post_secs_since_epoch]
    if not book_url:
        if debug:
            print('remove_reading_event no book_url')
        return False
    if not reader_books_json.get(book_url):
        if debug:
            print('remove_reading_event ' +
                  'book_url not found in reader_books_json ' + book_url)
        return False
    if not reader_books_json[book_url].get(book_event_type):
        if debug:
            print('remove_reading_event ' +
                  'book event not found in reader_books_json ' +
                  book_url + ' ' + book_event_type)
        return False
    del reader_books_json[book_url][book_event_type]
    if not save_json(reader_books_json, reader_books_filename):
        if debug:
            print('DEBUG: ' +
                  'remove_reading_event unable to save reader book event')
        return False
    print('reading status removed by ' + actor)
    return True


def _add_book_to_reader(reader_books_json: {}, book_dict: {},
                        debug: bool) -> bool:
    """Updates reader books
    """
    if not book_dict.get('published'):
        if debug:
            print('_add_book_to_reader no published field')
        return False
    book_url = book_dict['href']
    book_event_type = book_dict['type']
    if not reader_books_json.get(book_url):
        reader_books_json[book_url] = {}
        if debug:
            print('_add_book_to_reader first book')
    else:
        # has this book event already been stored?
        if reader_books_json[book_url].get(book_event_type):
            prev_book_dict = reader_books_json[book_url][book_event_type]
            if book_dict.get('updated'):
                if prev_book_dict.get('updated'):
                    if prev_book_dict['updated'] == book_dict['updated']:
                        if debug:
                            print('_add_book_to_reader ' +
                                  'updated date already seen')
                        return False
                else:
                    if prev_book_dict['published'] == book_dict['updated']:
                        if debug:
                            print('_add_book_to_reader ' +
                                  'published date already seen')
                        return False
            if prev_book_dict['published'] == book_dict['published']:

                return False
    # store the book event
    reader_books_json[book_url][book_event_type] = book_dict
    if 'timeline' not in reader_books_json:
        reader_books_json['timeline'] = {}
    published = book_dict['published']
    if book_dict.get('updated'):
        published = book_dict['updated']
    if '.' in published:
        published = published.split('.')[0] + 'Z'
    post_time_object = \
        date_from_string_format(published, ["%Y-%m-%dT%H:%M:%S%z",
                                            "%Y-%m-%dT%H:%M:%S%Z"])
    if post_time_object:
        baseline_time = date_epoch()
        days_diff = post_time_object - baseline_time
        post_secs_since_epoch = days_diff.total_seconds()
        reader_books_json['timeline'][post_secs_since_epoch] = book_url
        return True
    elif debug:
        print('_add_book_to_reader published date not recognised ' + published)
    return False


def _add_reader_to_book(book_json: {}, book_dict: {}) -> None:
    """Updates book with a new reader
    """
    book_event_type = book_dict['type']
    actor = book_dict['actor']
    if not book_json.get(actor):
        book_json[actor] = {
            book_event_type: book_dict
        }
        if book_dict.get('name'):
            book_json['title'] = remove_html(book_dict['name'])
        return
    book_json[actor][book_event_type] = book_dict
    if book_dict.get('name'):
        book_json['title'] = remove_html(book_dict['name'])


def _update_recent_books_list(base_dir: str, book_id: str,
                              debug: bool) -> None:
    """prepend a book to the recent books list
    """
    recent_books_filename = data_dir(base_dir) + '/recent_books.txt'
    if os.path.isfile(recent_books_filename):
        try:
            with open(recent_books_filename, 'r+',
                      encoding='utf-8') as fp_recent:
                content = fp_recent.read()
                if book_id + '\n' not in content:
                    fp_recent.seek(0, 0)
                    fp_recent.write(book_id + '\n' + content)
                    if debug:
                        print('DEBUG: recent book added')
        except OSError as ex:
            print('WARN: Failed to write entry to recent books ' +
                  recent_books_filename + ' ' + str(ex))
    else:
        try:
            with open(recent_books_filename, 'w+',
                      encoding='utf-8') as fp_recent:
                fp_recent.write(book_id + '\n')
        except OSError:
            print('EX: unable to write recent books ' +
                  recent_books_filename)


def _deduplicate_recent_books_list(base_dir: str,
                                   max_recent_books: int) -> None:
    """ Deduplicate and limit the length of the recent books list
    """
    recent_books_filename = data_dir(base_dir) + '/recent_books.txt'
    if not os.path.isfile(recent_books_filename):
        return

    # load recent books as a list
    recent_lines: list[str] = []
    try:
        with open(recent_books_filename, 'r',
                  encoding='utf-8') as fp_recent:
            recent_lines = fp_recent.read().split('\n')
    except OSError as ex:
        print('WARN: Failed to read recent books trim ' +
              recent_books_filename + ' ' + str(ex))

    # deduplicate the list
    new_recent_lines: list[str] = []
    for line in recent_lines:
        if line not in new_recent_lines:
            new_recent_lines.append(line)
    if len(new_recent_lines) < len(recent_lines):
        recent_lines = new_recent_lines
        result = ''
        for line in recent_lines:
            result += line + '\n'
        try:
            with open(recent_books_filename, 'w+',
                      encoding='utf-8') as fp_recent:
                fp_recent.write(result)
        except OSError:
            print('EX: unable to deduplicate recent books ' +
                  recent_books_filename)

    # remove excess lines from the list
    if len(recent_lines) > max_recent_books:
        result = ''
        for ctr in range(max_recent_books):
            result += recent_lines[ctr] + '\n'
        try:
            with open(recent_books_filename, 'w+',
                      encoding='utf-8') as fp_recent:
                fp_recent.write(result)
        except OSError:
            print('EX: unable to trim recent books ' +
                  recent_books_filename)


def store_book_events(base_dir: str,
                      post_json_object: {},
                      system_language: str,
                      languages_understood: [],
                      translate: {},
                      debug: bool,
                      max_recent_books: int,
                      books_cache: {},
                      max_cached_readers: int) -> bool:
    """Saves book events to file under accounts/reading/books
    and accounts/reading/readers
    """
    book_dict = get_reading_status(post_json_object,
                                   system_language,
                                   languages_understood,
                                   translate, debug)
    if not book_dict:
        if debug:
            print('DEBUG: no book event')
        return False
    dir_str = data_dir(base_dir)
    reading_path = dir_str + '/reading'
    if not os.path.isdir(dir_str):
        os.mkdir(dir_str)
    if not os.path.isdir(reading_path):
        os.mkdir(reading_path)
    books_path = reading_path + '/books'
    if not os.path.isdir(books_path):
        os.mkdir(books_path)
    readers_path = reading_path + '/readers'
    if not os.path.isdir(readers_path):
        os.mkdir(readers_path)

    actor = book_dict['actor']
    book_url = remove_id_ending(book_dict['href'])

    reader_books_filename = \
        readers_path + '/' + actor.replace('/', '#') + '.json'
    if debug:
        print('reader_books_filename: ' + reader_books_filename)
    reader_books_json = {}

    # get the reader from cache if possible
    if 'readers' not in books_cache:
        books_cache['readers'] = {}
    if books_cache['readers'].get(actor):
        reader_books_json = books_cache['readers'][actor]
    elif os.path.isfile(reader_books_filename):
        # if not in cache then load from file
        reader_books_json = load_json(reader_books_filename)
    if _add_book_to_reader(reader_books_json, book_dict, debug):
        if not save_json(reader_books_json, reader_books_filename):
            if debug:
                print('DEBUG: unable to save reader book event')
            return False

        # update the cache for this reader
        books_cache['readers'][actor] = reader_books_json
        if 'reader_list' not in books_cache:
            books_cache['reader_list']: list[str] = []
        if actor in books_cache['reader_list']:
            books_cache['reader_list'].remove(actor)
        books_cache['reader_list'].append(actor)
        # avoid too much caching
        if len(books_cache['reader_list']) > max_cached_readers:
            first_actor = books_cache['reader_list'][0]
            books_cache['reader_list'].remove(first_actor)
            del books_cache['readers'][actor]
    elif debug:
        print('_add_book_to_reader failed ' + str(book_dict))

    book_id = book_url.replace('/', '#')
    book_filename = books_path + '/' + book_id + '.json'
    book_json = {}
    if os.path.isfile(book_filename):
        book_json = load_json(book_filename)
    _add_reader_to_book(book_json, book_dict)
    if not save_json(book_json, book_filename):
        if debug:
            print('DEBUG: unable to save book reader')
        return False

    _update_recent_books_list(base_dir, book_id, debug)
    _deduplicate_recent_books_list(base_dir, max_recent_books)

    return True


def html_profile_book_list(base_dir: str, actor: str, no_of_books: int,
                           translate: {},
                           nickname: str, domain: str,
                           authorized: bool) -> str:
    """Returns html for displaying a list of books on a profile screen
    """
    reading_path = data_dir(base_dir) + '/reading'
    readers_path = reading_path + '/readers'
    reader_books_filename = \
        readers_path + '/' + actor.replace('/', '#') + '.json'
    reader_books_json = {}
    if not os.path.isfile(reader_books_filename):
        return ''
    reader_books_json = load_json(reader_books_filename)
    if not reader_books_json.get('timeline'):
        return ''
    # sort the timeline in descending order
    recent_books_json = \
        OrderedDict(sorted(reader_books_json['timeline'].items(),
                           reverse=True))
    html_str = '<div class="book_list_section">\n'
    html_str += '  <ul class="book_list">\n'
    ctr = 0
    for published_time_sec, book_url in recent_books_json.items():
        if not reader_books_json.get(book_url):
            continue
        book_rating = None
        book_wanted = False
        book_reading = False
        book_finished = False
        book_event_type = ''
        for event_type in ('want', 'finished', 'rated'):
            if not reader_books_json[book_url].get(event_type):
                continue
            book_dict = reader_books_json[book_url][event_type]
            if book_dict.get('name'):
                book_title = book_dict['name']
            if book_dict.get('image_url'):
                book_image_url = book_dict['image_url']
            if event_type == 'rated':
                book_rating = book_dict['rating']
                book_event_type = event_type
            elif event_type == 'want':
                book_wanted = True
                book_event_type = event_type
            elif event_type == 'reading':
                book_reading = True
                book_event_type = event_type
            elif event_type == 'finished':
                book_finished = True
                book_event_type = event_type
        if book_title:
            book_title = remove_html(book_title)
            html_str += '    <li class="book_event">\n'
            html_str += '      <span class="book_span">\n'
            html_str += '        <div class="book_span_div">\n'

            # book image
            if book_image_url:
                html_str += '          <a href="' + book_url + \
                    '" target="_blank" rel="nofollow noopener noreferrer">\n'
                html_str += '            <div class="book_image_div">\n'
                html_str += '              <img src="' + \
                    book_image_url + '" ' + \
                    'alt="' + book_title + '">\n'
                html_str += '            </div>\n'
                html_str += '          </a>\n'

            # book details
            html_str += '          <div class="book_details_div">\n'
            html_str += '            <a href="' + book_url + \
                '" target="_blank" rel="nofollow noopener noreferrer">\n'
            html_str += '              <b>' + book_title.title() + '</b></a>\n'
            if book_finished:
                html_str += '            <br>' + \
                    translate['finished reading'].title() + '\n'
            if book_wanted:
                html_str += '            <br>' + \
                    translate['Wanted'] + '\n'
            if book_reading:
                html_str += '            <br>' + \
                    translate['reading'].title() + '\n'
            # book star rating
            if book_rating is not None:
                html_str += '            <br>'
                for _ in range(int(book_rating)):
                    html_str += '⭐'
                html_str += ' (' + str(book_rating) + ')\n'
            # remove button
            if authorized:
                if actor.endswith('/users/' + nickname) and \
                   '://' + domain in actor:
                    html_str += \
                        '            <br>\n' + \
                        '            <form method="POST" action="' + \
                        '/users/' + nickname + '/removereadingstatus">\n' + \
                        '              ' + \
                        '<input type="hidden" name="actor" value="' + \
                        actor + '">\n' + \
                        '              ' + \
                        '<input type="hidden" ' + \
                        'name="publishedtimesec" value="' + \
                        str(published_time_sec) + '">\n' + \
                        '              ' + \
                        '<input type="hidden" ' + \
                        'name="bookeventtype" value="' + \
                        book_event_type + '">\n' + \
                        '              ' + \
                        '<button type="submit" class="button" ' + \
                        'name="submitRemoveReadingStatus">' + \
                        translate['Remove'] + '</button>\n' + \
                        '            </form>\n'
            html_str += '          </div>\n'

            html_str += '        </div>\n'
            html_str += '      </span>\n'
            html_str += '    </li>\n'
        ctr += 1
        if ctr >= no_of_books:
            break
    html_str += '  </ul>\n'
    html_str += '</div>\n'
    return html_str
