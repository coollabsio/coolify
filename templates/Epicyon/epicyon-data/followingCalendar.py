__filename__ = "followingCalendar.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Calendar"

import os


def _data_dir2(base_dir) -> str:
    """Returns the directory where account data is stored
    """
    return base_dir + '/accounts'


def _text_in_file2(text: str, filename: str,
                   case_sensitive: bool) -> bool:
    """is the given text in the given file?
    """
    if not case_sensitive:
        text = text.lower()
    content = ''
    try:
        with open(filename, 'r', encoding='utf-8') as fp_file:
            content = fp_file.read()
    except OSError:
        print('EX: unable to find text in missing file 2 ' + filename)
    if content:
        if not case_sensitive:
            content = content.lower()
        if text in content:
            return True
    return False


def _dir_acct(base_dir: str, nickname: str, domain: str) -> str:
    """Returns the directory of an account
    """
    return _data_dir2(base_dir) + '/' + nickname + '@' + domain


def _port_domain_remove(domain: str) -> str:
    """If the domain has a port appended then remove it
    eg. mydomain.com:80 becomes mydomain.com
    same as remove_domain_port in utils.py
    """
    if ':' in domain:
        if domain.startswith('did:'):
            return domain
        domain = domain.split(':')[0]
    return domain


def receiving_calendar_events(base_dir: str, nickname: str, domain: str,
                              following_nickname: str,
                              following_domain: str) -> bool:
    """Returns true if receiving calendar events from the given
    account from following.txt
    """
    if following_nickname == nickname and following_domain == domain:
        # reminder post
        return True
    calendar_filename = \
        _dir_acct(base_dir, nickname, domain) + '/followingCalendar.txt'
    handle = following_nickname + '@' + following_domain
    if not os.path.isfile(calendar_filename):
        following_filename = \
            _dir_acct(base_dir, nickname, domain) + '/following.txt'
        if not os.path.isfile(following_filename):
            return False
        # create a new calendar file from the following file
        following_handles = None
        try:
            with open(following_filename, 'r',
                      encoding='utf-8') as fp_following:
                following_handles = fp_following.read()
        except OSError:
            print('EX: receiving_calendar_events ' + following_filename)
        if following_handles:
            try:
                with open(calendar_filename, 'w+',
                          encoding='utf-8') as fp_cal:
                    fp_cal.write(following_handles)
            except OSError:
                print('EX: receiving_calendar_events 2 ' + calendar_filename)
    return _text_in_file2(handle + '\n', calendar_filename, False)


def _receive_calendar_events(base_dir: str, nickname: str, domain: str,
                             following_nickname: str,
                             following_domain: str,
                             add: bool) -> None:
    """Adds or removes a handle from the following.txt list into a list
    indicating whether to receive calendar events from that account
    """
    # check that a following file exists
    domain = _port_domain_remove(domain)
    following_filename = \
        _dir_acct(base_dir, nickname, domain) + '/following.txt'
    if not os.path.isfile(following_filename):
        print("WARN: following.txt doesn't exist for " +
              nickname + '@' + domain)
        return
    handle = following_nickname + '@' + following_domain

    # check that you are following this handle
    if not _text_in_file2(handle + '\n', following_filename, False):
        print('WARN: ' + handle + ' is not in ' + following_filename)
        return

    calendar_filename = \
        _dir_acct(base_dir, nickname, domain) + '/followingCalendar.txt'

    # get the contents of the calendar file, which is
    # a set of handles
    following_handles = ''
    if os.path.isfile(calendar_filename):
        print('Calendar file exists')
        try:
            with open(calendar_filename, 'r',
                      encoding='utf-8') as fp_calendar:
                following_handles = fp_calendar.read()
        except OSError:
            print('EX: _receive_calendar_events ' + calendar_filename)
    else:
        # create a new calendar file from the following file
        print('Creating calendar file ' + calendar_filename)
        following_handles = ''
        try:
            with open(following_filename, 'r',
                      encoding='utf-8') as fp_following:
                following_handles = fp_following.read()
        except OSError:
            print('EX: _receive_calendar_events 2 ' + calendar_filename)
        if add:
            try:
                with open(calendar_filename, 'w+',
                          encoding='utf-8') as fp_cal:
                    fp_cal.write(following_handles + handle + '\n')
            except OSError:
                print('EX: _receive_calendar_events unable to write ' +
                      calendar_filename)

    # already in the calendar file?
    if handle + '\n' in following_handles or \
       handle + '\n' in following_handles.lower():
        print(handle + ' exists in followingCalendar.txt')
        if add:
            # already added
            return
        # remove from calendar file
        new_following_handles = ''
        following_handles_list = following_handles.split('\n')
        handle_lower = handle.lower()
        for followed in following_handles_list:
            if followed.lower() != handle_lower:
                new_following_handles += followed + '\n'
        following_handles = new_following_handles
        # save the result
        try:
            with open(calendar_filename, 'w+',
                      encoding='utf-8') as fp_cal:
                fp_cal.write(following_handles)
        except OSError:
            print('EX: _receive_calendar_events 3 ' + calendar_filename)
    else:
        print(handle + ' not in followingCalendar.txt')
        # not already in the calendar file
        if add:
            # append to the list of handles
            following_handles += handle + '\n'
            try:
                with open(calendar_filename, 'w+',
                          encoding='utf-8') as fp_cal:
                    fp_cal.write(following_handles)
            except OSError:
                print('EX: _receive_calendar_events 4 ' + calendar_filename)


def add_person_to_calendar(base_dir: str, nickname: str, domain: str,
                           following_nickname: str,
                           following_domain: str) -> None:
    """Add a person to the calendar
    """
    _receive_calendar_events(base_dir, nickname, domain,
                             following_nickname, following_domain, True)


def remove_person_from_calendar(base_dir: str, nickname: str, domain: str,
                                following_nickname: str,
                                following_domain: str) -> None:
    """Remove a person from the calendar
    """
    _receive_calendar_events(base_dir, nickname, domain,
                             following_nickname, following_domain, False)
