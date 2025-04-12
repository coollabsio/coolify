__filename__ = "webapp_hashtagswarm.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface"

import os
from datetime import datetime, timezone
from flags import is_public_post
from utils import valid_hash_tag
from utils import remove_id_ending
from utils import resembles_url
from utils import has_object_dict
from utils import local_actor_url
from utils import date_from_string_format
from utils import file_last_modified
from utils import acct_dir
from utils import data_dir
from utils import get_nickname_from_actor
from utils import get_config_param
from utils import escape_text
from utils import date_utcnow
from utils import date_epoch
from utils import string_contains
from delete import remove_old_hashtags
from maps import add_tag_map_links
from maps import geocoords_from_map_link
from maps import get_map_links_from_post_content
from maps import get_location_from_post
from categories import set_hashtag_category
from categories import guess_hashtag_category
from categories import get_hashtag_categories
from categories import get_hashtag_category
from webapp_utils import set_custom_background
from webapp_utils import get_search_banner_file
from webapp_utils import get_content_warning_button
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer


def get_hashtag_categories_feed(base_dir: str,
                                hashtag_categories: {} = None) -> str:
    """Returns an rss feed for hashtag categories
    """
    if not hashtag_categories:
        hashtag_categories = get_hashtag_categories(base_dir, False, None)
    if not hashtag_categories:
        return None

    rss_str = \
        "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n" + \
        "<rss version=\"2.0\">\n" + \
        '<channel>\n' + \
        '    <title>#categories</title>\n'

    rss_date_str = \
        date_utcnow().strftime("%a, %d %b %Y %H:%M:%S UT")

    for category_str, hashtag_list in hashtag_categories.items():
        rss_str += \
            '<item>\n' + \
            '  <title>' + escape_text(category_str) + '</title>\n'
        list_str = ''
        for hashtag in hashtag_list:
            if ':' in hashtag:
                continue
            if '&' in hashtag:
                continue
            list_str += hashtag + ' '
        rss_str += \
            '  <description>' + \
            escape_text(list_str.strip()) + '</description>\n' + \
            '  <link/>\n' + \
            '  <pubDate>' + rss_date_str + '</pubDate>\n' + \
            '</item>\n'

    rss_str += \
        '</channel>\n' + \
        '</rss>\n'
    return rss_str


def html_hash_tag_swarm(base_dir: str, actor: str, translate: {}) -> str:
    """Returns a tag swarm of today's hashtags
    """
    max_tag_length = 42
    curr_time = date_utcnow()
    prev_time_epoch = date_epoch()
    days_since_epoch = (curr_time - prev_time_epoch).days
    days_since_epoch_str = str(days_since_epoch) + ' '
    days_since_epoch_str2 = str(days_since_epoch - 1) + ' '
    recently = days_since_epoch - 1
    tag_swarm: list[str] = []
    category_swarm: list[str] = []
    swarm_map: list[str] = []
    domain_histogram = {}

    # Load the blocked hashtags into memory.
    # This avoids needing to repeatedly load the blocked file for each hashtag
    blocked_str = ''
    global_blocking_filename = data_dir(base_dir) + '/blocking.txt'
    if os.path.isfile(global_blocking_filename):
        try:
            with open(global_blocking_filename, 'r',
                      encoding='utf-8') as fp_block:
                blocked_str = fp_block.read()
        except OSError:
            print('EX: html_hash_tag_swarm unable to read ' +
                  global_blocking_filename)

    for _, _, files in os.walk(base_dir + '/tags'):
        for fname in files:
            if not fname.endswith('.txt'):
                continue
            tags_filename = os.path.join(base_dir + '/tags', fname)
            if not os.path.isfile(tags_filename):
                continue

            # get last modified datetime
            mod_time_since_epoc = os.path.getmtime(tags_filename)
            last_modified_date = \
                datetime.fromtimestamp(mod_time_since_epoc,
                                       timezone.utc)
            file_days_since_epoch = \
                (last_modified_date - prev_time_epoch).days

            # check if the file was last modified within the previous
            # two days
            if file_days_since_epoch < recently:
                continue

            hash_tag_name = fname.split('.')[0]
            if len(hash_tag_name) > max_tag_length:
                # NoIncrediblyLongAndBoringHashtagsShownHere
                continue
            if string_contains(hash_tag_name, ['#', '&', '"', "'"]):
                continue
            if '#' + hash_tag_name + '\n' in blocked_str:
                continue

            try:
                with open(tags_filename, 'r', encoding='utf-8') as fp_tags:
                    # only read one line, which saves time and memory
                    last_tag = fp_tags.readline()
                    if not last_tag.startswith(days_since_epoch_str):
                        if not last_tag.startswith(days_since_epoch_str2):
                            continue
            except OSError:
                print('EX: html_hash_tag_swarm unable to read 2 ' +
                      tags_filename)
                continue

            try:
                with open(tags_filename, 'r', encoding='utf-8') as fp_tags:
                    while True:
                        line = fp_tags.readline()
                        if not line:
                            break
                        if '  ' not in line:
                            break
                        sections = line.split('  ')
                        if len(sections) != 3:
                            break
                        post_days_since_epoch_str = sections[0]
                        if not post_days_since_epoch_str.isdigit():
                            break
                        post_days_since_epoch = int(post_days_since_epoch_str)
                        if post_days_since_epoch < recently:
                            break
                        post_url = sections[2]
                        if '##' not in post_url:
                            break
                        post_domain = post_url.split('##')[1]
                        if '#' in post_domain:
                            post_domain = post_domain.split('#')[0]

                        if domain_histogram.get(post_domain):
                            domain_histogram[post_domain] = \
                                domain_histogram[post_domain] + 1
                        else:
                            domain_histogram[post_domain] = 1
                        tag_swarm.append(hash_tag_name)
                        category_filename = \
                            tags_filename.replace('.txt', '.category')
                        if os.path.isfile(category_filename):
                            category_str = \
                                get_hashtag_category(base_dir, hash_tag_name)
                            if category_str and \
                               len(category_str) < max_tag_length:
                                if '#' not in category_str and \
                                   '&' not in category_str and \
                                   '"' not in category_str and \
                                   "'" not in category_str:
                                    if category_str not in category_swarm:
                                        category_swarm.append(category_str)
                                    # check if the tag has an associated map
                                    tag_map_filename = \
                                        os.path.join(base_dir + '/tagmaps',
                                                     hash_tag_name + '.txt')
                                    if os.path.isfile(tag_map_filename):
                                        if category_str not in swarm_map:
                                            swarm_map.append(category_str)
                        break
            except OSError as exc:
                print('EX: html_hash_tag_swarm unable to read ' +
                      tags_filename + ' ' + str(exc))
        break

    if not tag_swarm:
        return ''
    tag_swarm.sort()

    # swarm of categories
    category_swarm_str = ''
    if category_swarm:
        if len(category_swarm) > 3:
            category_swarm.sort()
            for category_str in category_swarm:
                category_display_str = category_str
                # does this category have at least one hashtag with an
                # associated map?
                if category_str in swarm_map:
                    category_display_str = '📌' + category_str
                category_swarm_str += \
                    '<a href="' + actor + '/category/' + category_str + \
                    '" class="hashtagswarm"><b>' + \
                    category_display_str + '</b></a>\n'
            category_swarm_str += '<br>\n'

    # swarm of tags
    tag_swarm_str = ''
    for tag_name in tag_swarm:
        tag_display_name = tag_name
        tag_map_filename = \
            os.path.join(base_dir + '/tagmaps', tag_name + '.txt')
        if os.path.isfile(tag_map_filename):
            tag_display_name = '📌' + tag_name
        tag_swarm_str += \
            '<a href="' + actor + '/tags/' + tag_name + \
            '" class="hashtagswarm">' + tag_display_name + '</a>\n'

    if category_swarm_str:
        tag_swarm_str = \
            get_content_warning_button('alltags', translate, tag_swarm_str)

    tag_swarm_html = category_swarm_str + tag_swarm_str.strip() + '\n'
    return tag_swarm_html


def html_search_hashtag_category(translate: {},
                                 base_dir: str, path: str, domain: str,
                                 theme: str) -> str:
    """Show hashtags after selecting a category on the main search screen
    """
    actor = path.split('/category/')[0]
    category_str = path.split('/category/')[1].strip()
    search_nickname = get_nickname_from_actor(actor)
    if not search_nickname:
        return ''

    set_custom_background(base_dir, 'search-background', 'follow-background')

    css_filename = base_dir + '/epicyon-search.css'
    if os.path.isfile(base_dir + '/search.css'):
        css_filename = base_dir + '/search.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    html_str = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    # show a banner above the search box
    search_banner_file, search_banner_filename = \
        get_search_banner_file(base_dir, search_nickname, domain, theme)

    if os.path.isfile(search_banner_filename):
        html_str += '<a href="' + actor + '/search">\n'
        html_str += '<img loading="lazy" decoding="async" ' + \
            'class="timeline-banner" src="' + \
            actor + '/' + search_banner_file + '" alt="" /></a>\n'

    html_str += \
        '<div class="follow">' + \
        '<center><br><br><br>' + \
        '<h1><a href="' + actor + '/search"><b>' + \
        translate['Category'] + ': ' + category_str + '</b></a></h1>'

    hashtags_dict = get_hashtag_categories(base_dir, True, category_str)
    if hashtags_dict:
        for _, hashtag_list in hashtags_dict.items():
            hashtag_list.sort()
            for tag_name in hashtag_list:
                tag_display_name = tag_name
                tag_map_filename = \
                    os.path.join(base_dir + '/tagmaps', tag_name + '.txt')
                if os.path.isfile(tag_map_filename):
                    tag_display_name = '📌' + tag_name

                html_str += \
                    '<a href="' + actor + '/tags/' + tag_name + \
                    '" class="hashtagswarm">' + tag_display_name + '</a>\n'

    html_str += \
        '</center>' + \
        '</div>'
    html_str += html_footer()
    return html_str


def _update_cached_hashtag_swarm(base_dir: str, nickname: str, domain: str,
                                 http_prefix: str, domain_full: str,
                                 translate: {}) -> bool:
    """Updates the hashtag swarm stored as a file
    """
    cached_hashtag_swarm_filename = \
        acct_dir(base_dir, nickname, domain) + '/.hashtagSwarm'
    save_swarm = True
    if os.path.isfile(cached_hashtag_swarm_filename):
        last_modified = file_last_modified(cached_hashtag_swarm_filename)
        modified_date = None
        try:
            modified_date = \
                date_from_string_format(last_modified, ["%Y-%m-%dT%H:%M:%S%z"])
        except BaseException:
            print('EX: unable to parse last modified cache date ' +
                  str(last_modified))
        if modified_date:
            curr_date = date_utcnow()
            time_diff = curr_date - modified_date
            diff_mins = int(time_diff.total_seconds() / 60)
            if diff_mins < 30:
                # was saved recently, so don't save again
                # This avoids too much disk I/O
                save_swarm = False
                print('Not updating hashtag swarm')
            else:
                print('Updating cached hashtag swarm, last changed ' +
                      str(diff_mins) + ' minutes ago')
        else:
            print('WARN: no modified date for ' + str(last_modified))
    if save_swarm:
        actor = local_actor_url(http_prefix, nickname, domain_full)
        new_swarm_str = html_hash_tag_swarm(base_dir, actor, translate)
        if new_swarm_str:
            try:
                with open(cached_hashtag_swarm_filename, 'w+',
                          encoding='utf-8') as fp_swarm:
                    fp_swarm.write(new_swarm_str)
                    return True
            except OSError:
                print('EX: unable to write cached hashtag swarm ' +
                      cached_hashtag_swarm_filename)
        remove_old_hashtags(base_dir, 3)
    return False


def store_hash_tags(base_dir: str, nickname: str, domain: str,
                    http_prefix: str, domain_full: str,
                    post_json_object: {}, translate: {},
                    session) -> None:
    """Extracts hashtags from an incoming post and updates the
    relevant tags files.
    """
    if not is_public_post(post_json_object):
        return
    if not has_object_dict(post_json_object):
        return
    if not post_json_object['object'].get('tag'):
        return
    if not post_json_object.get('id'):
        return
    if not isinstance(post_json_object['object']['tag'], list):
        return
    tags_dir = base_dir + '/tags'

    # add tags directory if it doesn't exist
    if not os.path.isdir(tags_dir):
        print('Creating tags directory')
        os.mkdir(tags_dir)

    # obtain any map links and these can be associated with hashtags
    # get geolocations from content
    map_links: list[str] = []
    published = None
    if 'content' in post_json_object['object']:
        published = post_json_object['object']['published']
        post_content = post_json_object['object']['content']
        map_links += get_map_links_from_post_content(post_content, session)
    # get geolocation from tags
    location_str = get_location_from_post(post_json_object)
    if location_str:
        if resembles_url(location_str):
            zoom, latitude, longitude = \
                geocoords_from_map_link(location_str,
                                        'openstreetmap.org', session)
            if latitude and longitude and zoom and \
               location_str not in map_links:
                map_links.append(location_str)
    tag_maps_dir = base_dir + '/tagmaps'
    if map_links:
        # add tagmaps directory if it doesn't exist
        if not os.path.isdir(tag_maps_dir):
            print('Creating tagmaps directory')
            os.mkdir(tag_maps_dir)

    post_url = remove_id_ending(post_json_object['id'])
    post_url = post_url.replace('/', '#')
    hashtags_ctr = 0
    for tag in post_json_object['object']['tag']:
        if not tag.get('type'):
            continue
        if not isinstance(tag['type'], str):
            continue
        if tag['type'] != 'Hashtag':
            continue
        if not tag.get('name'):
            continue
        tag_name = tag['name'].replace('#', '').strip()
        if not valid_hash_tag(tag_name):
            continue
        tags_filename = tags_dir + '/' + tag_name + '.txt'
        days_diff = date_utcnow() - date_epoch()
        days_since_epoch = days_diff.days
        tag_line = \
            str(days_since_epoch) + '  ' + nickname + '  ' + post_url + '\n'
        if map_links and published:
            add_tag_map_links(tag_maps_dir, tag_name, map_links,
                              published, post_url)
        hashtag_added = False
        if not os.path.isfile(tags_filename):
            try:
                with open(tags_filename, 'w+', encoding='utf-8') as fp_tags:
                    fp_tags.write(tag_line)
                    hashtag_added = True
            except OSError:
                print('EX: store_hash_tags unable to write ' + tags_filename)
        else:
            content = ''
            try:
                with open(tags_filename, 'r', encoding='utf-8') as fp_tags:
                    content = fp_tags.read()
            except OSError:
                print('EX: store_hash_tags failed to read ' + tags_filename)
            if post_url not in content:
                content = tag_line + content
                try:
                    with open(tags_filename, 'w+',
                              encoding='utf-8') as fp_tags2:
                        fp_tags2.write(content)
                        hashtag_added = True
                except OSError as ex:
                    print('EX: Failed to write entry to tags file ' +
                          tags_filename + ' ' + str(ex))

        if hashtag_added:
            hashtags_ctr += 1

            # automatically assign a category to the tag if possible
            category_filename = tags_dir + '/' + tag_name + '.category'
            if not os.path.isfile(category_filename):
                hashtag_categories = \
                    get_hashtag_categories(base_dir, False, None)
                category_str = \
                    guess_hashtag_category(tag_name, hashtag_categories, 6)
                if category_str:
                    set_hashtag_category(base_dir, tag_name,
                                         category_str, False, False)

    # if some hashtags were found then recalculate the swarm
    # ready for later display
    if hashtags_ctr > 0:
        _update_cached_hashtag_swarm(base_dir, nickname, domain,
                                     http_prefix, domain_full, translate)
