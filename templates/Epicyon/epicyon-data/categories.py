__filename__ = "categories.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "RSS Feeds"

import os
import datetime
from utils import data_dir
from utils import date_utcnow
from utils import date_epoch
from utils import replace_strings

MAX_TAG_LENGTH = 42

INVALID_HASHTAG_CHARS = (',', ' ', '<', ';', '\\', '"', '&', '#')


def get_hashtag_category(base_dir: str, hashtag: str) -> str:
    """Returns the category for the hashtag
    """
    category_filename = base_dir + '/tags/' + hashtag + '.category'
    if not os.path.isfile(category_filename):
        category_filename = base_dir + '/tags/' + hashtag.title() + '.category'
        if not os.path.isfile(category_filename):
            category_filename = \
                base_dir + '/tags/' + hashtag.upper() + '.category'
            if not os.path.isfile(category_filename):
                return ''

    category_str = None
    try:
        with open(category_filename, 'r', encoding='utf-8') as fp_category:
            category_str = fp_category.read()
    except OSError:
        print('EX: unable to read category ' + category_filename)
    except UnicodeEncodeError as ex:
        print('EX: unable to read category unicode ' + category_filename +
              ' ' + str(ex))
    if category_str:
        return category_str
    return ''


def load_city_hashtags(base_dir: str, translate: {}) -> None:
    """create hashtag categories for cities
    """
    category_str = 'places'
    if translate.get(category_str):
        category_str = translate[category_str]

    replacements = {
        ' & ': ' and ',
        '/': ''
    }
    replacements2 = {
        '-': '',
        ' ': ''
    }
    for _, _, files in os.walk(base_dir + '/data/cities'):
        for cities_file in files:
            if not cities_file.endswith('.txt'):
                continue
            cities_filename = base_dir + '/data/cities/' + cities_file
            if not os.path.isfile(cities_filename):
                continue
            cities: list[str] = []
            try:
                with open(cities_filename, 'r', encoding='utf-8') as fp_cities:
                    cities = fp_cities.read().split('\n')
            except OSError:
                print('EX: unable to load cities file ' + cities_filename)
            if not cities:
                continue
            for hashtag in cities:
                hashtag = hashtag.lower().strip()
                hashtag = replace_strings(hashtag, replacements)

                hashtag2 = replace_strings(hashtag, replacements2)
                city_filename = base_dir + '/tags/' + hashtag2 + '.category'
                if not os.path.isfile(city_filename):
                    try:
                        with open(city_filename, 'w+',
                                  encoding='utf-8') as fp_city:
                            fp_city.write(category_str)
                    except OSError:
                        print('EX: unable to write city category ' +
                              city_filename)
                if '-' in hashtag:
                    section = hashtag.split('-')
                    new_hashtag = ''
                    for text in section:
                        new_hashtag += text.lower().title()
                    hashtag2 = new_hashtag
                    city_filename = \
                        base_dir + '/tags/' + hashtag2 + '.category'
                    if not os.path.isfile(city_filename):
                        try:
                            with open(city_filename, 'w+',
                                      encoding='utf-8') as fp_city:
                                fp_city.write(category_str)
                        except OSError:
                            print('EX: unable to write city category2 ' +
                                  city_filename)
                if ' ' in hashtag:
                    section = hashtag.split(' ')
                    new_hashtag = ''
                    for text in section:
                        new_hashtag += text.lower().title()
                    hashtag2 = new_hashtag
                    city_filename = \
                        base_dir + '/tags/' + hashtag2 + '.category'
                    if not os.path.isfile(city_filename):
                        try:
                            with open(city_filename, 'w+',
                                      encoding='utf-8') as fp_city:
                                fp_city.write(category_str)
                        except OSError:
                            print('EX: unable to write city category3 ' +
                                  city_filename)


def get_hashtag_categories(base_dir: str,
                           recent: bool, category: str) -> None:
    """Returns a dictionary containing hashtag categories
    """
    hashtag_categories = {}

    if recent:
        curr_time = date_utcnow()
        days_since_epoch = (curr_time - date_epoch()).days
        recently = days_since_epoch - 1

    for _, _, files in os.walk(base_dir + '/tags'):
        for catfile in files:
            if not catfile.endswith('.category'):
                continue
            category_filename = os.path.join(base_dir + '/tags', catfile)
            if not os.path.isfile(category_filename):
                continue
            hashtag = catfile.split('.')[0]
            if len(hashtag) > MAX_TAG_LENGTH:
                continue

            category_str = None
            try:
                with open(category_filename, 'r',
                          encoding='utf-8') as fp_category:
                    category_str = fp_category.read()
            except OSError:
                print('EX: get_hashtag_categories ' + category_filename)
            except UnicodeEncodeError as ex:
                print('EX: get_hashtag_categories unicode ' +
                      category_filename + ' ' + str(ex))

            if not category_str:
                continue

            if category:
                # only return a dictionary for a specific category
                if category_str != category:
                    continue

            if recent:
                tags_filename = base_dir + '/tags/' + hashtag + '.txt'
                if not os.path.isfile(tags_filename):
                    continue
                mod_time_since_epoc = \
                    os.path.getmtime(tags_filename)
                last_modified_date = \
                    datetime.datetime.fromtimestamp(mod_time_since_epoc,
                                                    datetime.timezone.utc)
                file_days_since_epoch = \
                    (last_modified_date - date_epoch()).days
                if file_days_since_epoch < recently:
                    continue

            if not hashtag_categories.get(category_str):
                hashtag_categories[category_str] = [hashtag]
            else:
                if hashtag not in hashtag_categories[category_str]:
                    hashtag_categories[category_str].append(hashtag)
        break
    return hashtag_categories


def update_hashtag_categories(base_dir: str) -> None:
    """Regenerates the list of hashtag categories
    """
    category_list_filename = data_dir(base_dir) + '/categoryList.txt'
    hashtag_categories = get_hashtag_categories(base_dir, False, None)
    if not hashtag_categories:
        if os.path.isfile(category_list_filename):
            try:
                os.remove(category_list_filename)
            except OSError:
                print('EX: update_hashtag_categories ' +
                      'unable to delete cached category list ' +
                      category_list_filename)
        return

    category_list: list[str] = []
    for category_str, _ in hashtag_categories.items():
        category_list.append(category_str)
    category_list.sort()

    category_list_str = ''
    for category_str in category_list:
        category_list_str += category_str + '\n'

    # save a list of available categories for quick lookup
    try:
        with open(category_list_filename, 'w+',
                  encoding='utf-8') as fp_category:
            fp_category.write(category_list_str)
    except OSError:
        print('EX: unable to write category ' + category_list_filename)


def _valid_hashtag_category(category: str) -> bool:
    """Returns true if the category name is valid
    """
    if not category:
        return False

    for char in INVALID_HASHTAG_CHARS:
        if char in category:
            return False

    # too long
    if len(category) > 40:
        return False

    return True


def set_hashtag_category(base_dir: str, hashtag: str, category: str,
                         update: bool, force: bool) -> bool:
    """Sets the category for the hashtag
    """
    if not _valid_hashtag_category(category):
        return False

    if not force:
        hashtag_filename = base_dir + '/tags/' + hashtag + '.txt'
        if not os.path.isfile(hashtag_filename):
            hashtag = hashtag.title()
            hashtag_filename = base_dir + '/tags/' + hashtag + '.txt'
            if not os.path.isfile(hashtag_filename):
                hashtag = hashtag.upper()
                hashtag_filename = base_dir + '/tags/' + hashtag + '.txt'
                if not os.path.isfile(hashtag_filename):
                    return False

    if not os.path.isdir(base_dir + '/tags'):
        os.mkdir(base_dir + '/tags')
    category_filename = base_dir + '/tags/' + hashtag + '.category'
    if force:
        # don't overwrite any existing categories
        if os.path.isfile(category_filename):
            return False

    category_written = False
    try:
        with open(category_filename, 'w+', encoding='utf-8') as fp_category:
            fp_category.write(category)
            category_written = True
    except OSError as ex:
        print('EX: unable to write category ' + category_filename +
              ' ' + str(ex))
    except UnicodeEncodeError as ex:
        print('EX: unable to write category unicode ' + category_filename +
              ' ' + str(ex))

    if category_written:
        if update:
            update_hashtag_categories(base_dir)
        return True

    return False


def guess_hashtag_category(tag_name: str, hashtag_categories: {},
                           min_tag_length: int) -> str:
    """Tries to guess a category for the given hashtag.
    This works by trying to find the longest similar hashtag
    """
    if len(tag_name) < min_tag_length:
        return ''

    category_matched = ''
    tag_matched_len = 0
    finished = False

    for category_str, hashtag_list in hashtag_categories.items():
        if finished:
            break
        for hashtag in hashtag_list:
            if hashtag == tag_name:
                # exact match
                category_matched = category_str
                finished = True
                break
            if len(hashtag) < min_tag_length:
                # avoid matching very small strings which often
                # lead to spurious categories
                continue
            if hashtag not in tag_name:
                if tag_name not in hashtag:
                    continue
            if not category_matched:
                tag_matched_len = len(hashtag)
                category_matched = category_str
            else:
                # match the longest tag
                if len(hashtag) > tag_matched_len:
                    category_matched = category_str
    if not category_matched:
        return ''
    return category_matched
