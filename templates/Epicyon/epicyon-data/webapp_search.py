__filename__ = "webapp_search.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface"

import os
from shutil import copyfile
import urllib.parse
from flags import is_editor
from flags import is_public_post
from utils import get_person_icon
from utils import data_dir
from utils import get_post_attachments
from utils import get_url_from_post
from utils import date_from_string_format
from utils import get_attributed_to
from utils import get_actor_from_post_id
from utils import remove_html
from utils import harmless_markup
from utils import remove_id_ending
from utils import has_object_dict
from utils import acct_handle_dir
from utils import get_base_content_from_post
from utils import is_account_dir
from utils import get_config_param
from utils import get_full_domain
from utils import load_json
from utils import get_nickname_from_actor
from utils import locate_post
from utils import first_paragraph_from_string
from utils import search_box_posts
from utils import get_alt_path
from utils import acct_dir
from utils import local_actor_url
from utils import escape_text
from skills import no_of_actor_skills
from skills import get_skills_from_list
from categories import get_hashtag_category
from feeds import rss2tag_header
from feeds import rss2tag_footer
from webapp_utils import get_banner_file
from webapp_utils import html_common_emoji
from webapp_utils import set_custom_background
from webapp_utils import html_keyboard_navigation
from webapp_utils import html_header_with_external_style
from webapp_utils import html_footer
from webapp_utils import get_search_banner_file
from webapp_utils import html_post_separator
from webapp_utils import html_search_result_share
from webapp_post import individual_post_as_html
from webapp_hashtagswarm import html_hash_tag_swarm
from maps import html_hashtag_maps
from session import get_json_valid
from session import get_json


def html_search_emoji(translate: {}, base_dir: str, search_str: str,
                      nickname: str, domain: str, theme: str,
                      access_keys: {}) -> str:
    """Search results for emoji
    """
    # emoji.json is generated so that it can be customized and the changes
    # will be retained even if default_emoji.json is subsequently updated
    if not os.path.isfile(base_dir + '/emoji/emoji.json'):
        copyfile(base_dir + '/emoji/default_emoji.json',
                 base_dir + '/emoji/emoji.json')

    search_str = search_str.lower().replace(':', '').strip('\n').strip('\r')
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    emoji_lookup_filename = base_dir + '/emoji/emoji.json'
    custom_emoji_lookup_filename = base_dir + '/emojicustom/emoji.json'

    # create header
    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    emoji_form = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    # show top banner
    if nickname and domain and theme:
        banner_file, _ = \
            get_banner_file(base_dir, nickname, domain, theme)
        emoji_form += \
            '<header>\n' + \
            '<a href="/users/' + nickname + '/search" title="' + \
            translate['Search and follow'] + '" alt="' + \
            translate['Search and follow'] + '" ' + \
            'aria-flowto="containerHeader" tabindex="1" accesskey="' + \
            access_keys['menuSearch'] + '">\n'
        emoji_form += \
            '<img loading="lazy" decoding="async" ' + \
            'class="timeline-banner" alt="" ' + \
            'src="/users/' + nickname + '/' + banner_file + '" /></a>\n' + \
            '</header>\n'

    emoji_form += '<center><h1>' + \
        translate['Emoji Search'] + \
        '</h1></center>'

    # does the lookup file exist?
    if not os.path.isfile(emoji_lookup_filename):
        emoji_form += '<center><h5>' + \
            translate['No results'] + '</h5></center>'
        emoji_form += html_footer()
        return emoji_form

    emoji_json = load_json(emoji_lookup_filename)
    if emoji_json:
        if os.path.isfile(custom_emoji_lookup_filename):
            custom_emoji_json = load_json(custom_emoji_lookup_filename)
            if custom_emoji_json:
                emoji_json = dict(emoji_json, **custom_emoji_json)

        results = {}
        for emoji_name, filename in emoji_json.items():
            if search_str in emoji_name:
                results[emoji_name] = filename + '.png'
        for emoji_name, filename in emoji_json.items():
            if emoji_name in search_str:
                results[emoji_name] = filename + '.png'

        if not results:
            emoji_form += '<center><h5>' + \
                translate['No results'] + '</h5></center>'

        heading_shown = False
        emoji_form += '<center>'
        msg_str1 = translate['Copy the text then paste it into your post']
        msg_str2 = ':<img loading="lazy" decoding="async" ' + \
            'class="searchEmoji" src="/emoji/'
        for emoji_name, filename in results.items():
            if not os.path.isfile(base_dir + '/emoji/' + filename):
                if not os.path.isfile(base_dir + '/emojicustom/' + filename):
                    continue
            if not heading_shown:
                emoji_form += \
                    '<center><h5>' + msg_str1 + '</h5></center>'
                heading_shown = True
            emoji_form += \
                '<h3>:' + emoji_name + msg_str2 + filename + '"/></h3>'
        emoji_form += '</center>'

    emoji_form += html_footer()
    return emoji_form


def _match_shared_item(search_str_lower_list: [],
                       shared_item: {}) -> bool:
    """Returns true if the shared item matches search criteria
    """
    for search_substr in search_str_lower_list:
        search_substr = search_substr.strip()
        if shared_item.get('location'):
            if search_substr in shared_item['location'].lower():
                return True
        if search_substr in shared_item['summary'].lower():
            return True
        if search_substr in shared_item['displayName'].lower():
            return True
        if search_substr in shared_item['category'].lower():
            return True
    return False


def _html_search_result_share_page(actor: str, domain_full: str,
                                   calling_domain: str, page_number: int,
                                   search_str_lower: str, translate: {},
                                   previous: bool) -> str:
    """Returns the html for the previous button on shared items search results
    """
    post_actor = get_alt_path(actor, domain_full, calling_domain)
    # previous page link, needs to be a POST
    if previous:
        page_number -= 1
        title_str = translate['Page up']
        image_url = 'pageup.png'
    else:
        page_number += 1
        title_str = translate['Page down']
        image_url = 'pagedown.png'
    shared_items_form = \
        '<form method="POST" action="' + post_actor + '/searchhandle?page=' + \
        str(page_number) + '">\n'
    shared_items_form += \
        '  <input type="hidden" ' + 'name="actor" value="' + actor + '">\n'
    shared_items_form += \
        '  <input type="hidden" ' + 'name="searchtext" value="' + \
        search_str_lower + '"><br>\n'
    shared_items_form += \
        '  <center>\n' + '    <a href="' + actor + \
        '" type="submit" name="submitSearch">\n'
    shared_items_form += \
        '    <img loading="lazy" decoding="async" ' + \
        'class="pageicon" src="/icons' + \
        '/' + image_url + '" title="' + title_str + \
        '" alt="' + title_str + '"/></a>\n'
    shared_items_form += '  </center>\n'
    shared_items_form += '</form>\n'
    return shared_items_form


def _html_shares_result(base_dir: str, shares_json: {}, page_number: int,
                        results_per_page: int,
                        search_str_lower_list: [], curr_page: int, ctr: int,
                        calling_domain: str, http_prefix: str,
                        domain_full: str, contact_nickname: str, actor: str,
                        results_exist: bool, search_str_lower: str,
                        translate: {},
                        shares_file_type: str) -> (bool, int, int, str):
    """Result for shared items search
    """
    shared_items_form = ''
    if curr_page > page_number:
        return results_exist, curr_page, ctr, shared_items_form

    for name, shared_item in shares_json.items():
        if _match_shared_item(search_str_lower_list, shared_item):
            if curr_page == page_number:
                # show individual search result
                shared_items_form += \
                    html_search_result_share(base_dir, shared_item, translate,
                                             http_prefix, domain_full,
                                             contact_nickname,
                                             name, actor, shares_file_type,
                                             shared_item['category'], False)
                if not results_exist and curr_page > 1:
                    # show the previous page button
                    shared_items_form += \
                        _html_search_result_share_page(actor, domain_full,
                                                       calling_domain,
                                                       page_number,
                                                       search_str_lower,
                                                       translate, True)
                results_exist = True
            ctr += 1
            if ctr >= results_per_page:
                curr_page += 1
                if curr_page > page_number:
                    # show the next page button
                    shared_items_form += \
                        _html_search_result_share_page(actor, domain_full,
                                                       calling_domain,
                                                       page_number,
                                                       search_str_lower,
                                                       translate, False)
                    return results_exist, curr_page, ctr, shared_items_form
                ctr = 0
    return results_exist, curr_page, ctr, shared_items_form


def html_search_shared_items(translate: {},
                             base_dir: str, search_str: str,
                             page_number: int,
                             results_per_page: int,
                             http_prefix: str,
                             domain_full: str, actor: str,
                             calling_domain: str,
                             shared_items_federated_domains: [],
                             shares_file_type: str,
                             nickname: str, domain: str, theme_name: str,
                             access_keys: {}) -> str:
    """Search results for shared items
    """
    curr_page = 1
    ctr = 0
    shared_items_form = ''
    search_str_lower = urllib.parse.unquote(search_str)
    search_str_lower = search_str_lower.lower().strip('\n').strip('\r')
    search_str_lower_list = search_str_lower.split('+')
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    shared_items_form = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)
    if shares_file_type == 'shares':
        title_str = translate['Shared Items Search']
    else:
        title_str = translate['Wanted Items Search']

    # show top banner
    if nickname and domain and theme_name:
        banner_file, _ = \
            get_banner_file(base_dir, nickname, domain, theme_name)
        shared_items_form += \
            '<header>\n' + \
            '<a href="/users/' + nickname + '/search" title="' + \
            translate['Search and follow'] + '" alt="' + \
            translate['Search and follow'] + '" ' + \
            'aria-flowto="containerHeader" tabindex="1" accesskey="' + \
            access_keys['menuSearch'] + '">\n'
        shared_items_form += \
            '<img loading="lazy" decoding="async" ' + \
            'class="timeline-banner" alt="" ' + \
            'src="/users/' + nickname + '/' + banner_file + '" /></a>\n' + \
            '</header>\n'

    shared_items_form += \
        '<center><h1>' + \
        '<a href="' + actor + '/search">' + title_str + '</a></h1></center>'
    results_exist = False
    dir_str = data_dir(base_dir)
    for _, dirs, files in os.walk(dir_str):
        for handle in dirs:
            if not is_account_dir(handle):
                continue
            contact_nickname = handle.split('@')[0]
            shares_filename = acct_handle_dir(base_dir, handle) + \
                '/' + shares_file_type + '.json'
            if not os.path.isfile(shares_filename):
                continue

            shares_json = load_json(shares_filename)
            if not shares_json:
                continue

            (results_exist, curr_page, ctr,
             result_str) = _html_shares_result(base_dir, shares_json,
                                               page_number,
                                               results_per_page,
                                               search_str_lower_list,
                                               curr_page, ctr,
                                               calling_domain, http_prefix,
                                               domain_full,
                                               contact_nickname,
                                               actor, results_exist,
                                               search_str_lower, translate,
                                               shares_file_type)
            shared_items_form += result_str

            if curr_page > page_number:
                break
        break

    # search federated shared items
    if shares_file_type == 'shares':
        catalogs_dir = base_dir + '/cache/catalogs'
    else:
        catalogs_dir = base_dir + '/cache/wantedItems'
    if curr_page <= page_number and os.path.isdir(catalogs_dir):
        for _, dirs, files in os.walk(catalogs_dir):
            for fname in files:
                if '#' in fname:
                    continue
                if not fname.endswith('.' + shares_file_type + '.json'):
                    continue
                federated_domain = fname.split('.')[0]
                if federated_domain not in shared_items_federated_domains:
                    continue
                shares_filename = catalogs_dir + '/' + fname
                shares_json = load_json(shares_filename)
                if not shares_json:
                    continue

                (results_exist, curr_page, ctr,
                 result_str) = _html_shares_result(base_dir, shares_json,
                                                   page_number,
                                                   results_per_page,
                                                   search_str_lower_list,
                                                   curr_page, ctr,
                                                   calling_domain, http_prefix,
                                                   domain_full,
                                                   contact_nickname,
                                                   actor, results_exist,
                                                   search_str_lower, translate,
                                                   shares_file_type)
                shared_items_form += result_str

                if curr_page > page_number:
                    break
            break

    if not results_exist:
        shared_items_form += \
            '<center><h5>' + translate['No results'] + '</h5></center>\n'
    shared_items_form += html_footer()
    return shared_items_form


def html_search_emoji_text_entry(translate: {},
                                 base_dir: str, path: str) -> str:
    """Search for an emoji by name
    """
    # emoji.json is generated so that it can be customized and the changes
    # will be retained even if default_emoji.json is subsequently updated
    if not os.path.isfile(base_dir + '/emoji/emoji.json'):
        copyfile(base_dir + '/emoji/default_emoji.json',
                 base_dir + '/emoji/emoji.json')

    actor = path.replace('/search', '')

    set_custom_background(base_dir, 'search-background', 'follow-background')

    css_filename = base_dir + '/epicyon-follow.css'
    if os.path.isfile(base_dir + '/follow.css'):
        css_filename = base_dir + '/follow.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    emoji_str = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)
    emoji_str += '<div class="follow">\n'
    emoji_str += '  <div class="followAvatar">\n'
    emoji_str += '  <center>\n'
    emoji_str += \
        '  <p class="followText">' + \
        translate['Enter an emoji name to search for'] + '</p>\n'
    emoji_str += '  <form role="search" method="POST" action="' + \
        actor + '/searchhandleemoji">\n'
    emoji_str += '    <input type="hidden" name="actor" value="' + \
        actor + '">\n'
    emoji_str += '    <input type="text" name="searchtext" autofocus><br>\n'
    emoji_str += \
        '    <button type="submit" class="button" name="submitSearch">' + \
        translate['Search'] + '</button>\n'
    emoji_str += '  </form>\n'
    emoji_str += '  </center>\n'
    emoji_str += '  </div>\n'
    emoji_str += '  <center>\n'
    emoji_str += '    <div class="container"><p>\n'
    emoji_str += html_common_emoji(base_dir, 16) + '\n'
    emoji_str += '    </p></div>\n'
    emoji_str += '  </center>\n'
    emoji_str += '</div>\n'
    emoji_str += html_footer()
    return emoji_str


def html_search(translate: {}, base_dir: str, path: str, domain: str,
                default_timeline: str, theme: str,
                text_mode_banner: str, access_keys: {}) -> str:
    """Search called from the timeline icon
    """
    actor = path.replace('/search', '')
    search_nickname = get_nickname_from_actor(actor)
    if not search_nickname:
        return ''

    set_custom_background(base_dir, 'search-background', 'follow-background')

    css_filename = base_dir + '/epicyon-search.css'
    if os.path.isfile(base_dir + '/search.css'):
        css_filename = base_dir + '/search.css'

    # set a search banner
    search_banner_filename = \
        acct_dir(base_dir, search_nickname, domain) + \
        '/search_banner.png'
    if not os.path.isfile(search_banner_filename):
        if os.path.isfile(base_dir +
                          '/theme/' + theme + '/search_banner.png'):
            copyfile(base_dir +
                     '/theme/' + theme + '/search_banner.png',
                     search_banner_filename)
    users_path = '/users/' + search_nickname

    # show a banner above the search box
    search_banner_file, search_banner_filename = \
        get_search_banner_file(base_dir, search_nickname, domain, theme)
    search_banner_path = users_path + '/' + search_banner_file

    instance_title = get_config_param(base_dir, 'instanceTitle')

    # these images are pre-loaded to prevent the web page from
    # jumping around when rendering
    preload_images = [search_banner_path]

    follow_str = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    text_mode_banner_str = html_keyboard_navigation(text_mode_banner, {}, {},
                                                    None, None, None, False)
    if text_mode_banner_str is None:
        text_mode_banner_str = ''

    if os.path.isfile(search_banner_filename):
        timeline_key = access_keys['menuTimeline']
        follow_str += \
            '<header>\n' + text_mode_banner_str + \
            '<a href="' + users_path + '/' + default_timeline + '" title="' + \
            translate['Switch to timeline view'] + '" alt="' + \
            translate['Switch to timeline view'] + '" ' + \
            'accesskey="' + timeline_key + '">\n'
        follow_str += '<img loading="lazy" decoding="async" ' + \
            'class="timeline-banner" src="' + \
            search_banner_path + '" alt="" /></a>\n' + \
            '</header>\n'

    # show the search box
    follow_str += '<div class="follow">\n'
    follow_str += '  <div class="followAvatar">\n'
    follow_str += '  <center>\n'
    follow_str += \
        '  <p class="followText">' + translate['Search screen text'] + '</p>\n'
    follow_str += '  <form role="search" method="POST" ' + \
        'accept-charset="UTF-8" action="' + actor + '/searchhandle">\n'
    follow_str += \
        '    <input type="hidden" name="actor" value="' + actor + '">\n'
    follow_str += '    <input type="text" name="searchtext" autofocus><br>\n'
    submit_key = access_keys['submitButton']
    follow_str += '    <button type="submit" class="button" ' + \
        'name="submitSearch" accesskey="' + submit_key + '">' + \
        translate['Search'] + '</button>\n'
    follow_str += '  </form>\n'

    cached_hashtag_swarm_filename = \
        acct_dir(base_dir, search_nickname, domain) + '/.hashtagSwarm'
    swarm_str = ''
    if os.path.isfile(cached_hashtag_swarm_filename):
        try:
            with open(cached_hashtag_swarm_filename, 'r',
                      encoding='utf-8') as fp_swarm:
                swarm_str = fp_swarm.read()
        except OSError:
            print('EX: html_search unable to read cached hashtag swarm ' +
                  cached_hashtag_swarm_filename)
    if not swarm_str:
        swarm_str = html_hash_tag_swarm(base_dir, actor, translate)
        if swarm_str:
            try:
                with open(cached_hashtag_swarm_filename, 'w+',
                          encoding='utf-8') as fp_hash:
                    fp_hash.write(swarm_str)
            except OSError:
                print('EX: html_search unable to save cached hashtag swarm ' +
                      cached_hashtag_swarm_filename)

    follow_str += '  <p class="hashtagswarm">' + swarm_str + '</p><br>\n'
    follow_str += '  </center>\n'
    follow_str += '  </div>\n'
    follow_str += '</div>\n'
    follow_str += html_footer()
    return follow_str


def html_skills_search(actor: str, translate: {}, base_dir: str,
                       skillsearch: str, instance_only: bool,
                       posts_per_page: int,
                       nickname: str, domain: str, theme_name: str,
                       access_keys: {}) -> str:
    """Show a page containing search results for a skill
    """
    if skillsearch.startswith('*'):
        skillsearch = skillsearch[1:].strip()

    skillsearch = skillsearch.lower().strip('\n').strip('\r')

    results: list[str] = []
    # search instance accounts
    dir_str = data_dir(base_dir)
    for subdir, _, files in os.walk(dir_str + '/'):
        for fname in files:
            if not fname.endswith('.json'):
                continue
            if not is_account_dir(fname):
                continue
            actor_filename = os.path.join(subdir, fname)
            actor_json = load_json(actor_filename)
            if not actor_json:
                continue
            if actor_json.get('id') and \
               no_of_actor_skills(actor_json) > 0 and \
               actor_json.get('name') and \
               actor_json.get('icon'):
                actor = actor_json['id']
                actor_skills_list = actor_json['hasOccupation']['skills']
                skills = get_skills_from_list(actor_skills_list)
                for skill_name, skill_level in skills.items():
                    skill_name = skill_name.lower()
                    if not (skill_name in skillsearch or
                            skillsearch in skill_name):
                        continue
                    skill_level_str = str(skill_level)
                    if skill_level < 100:
                        skill_level_str = '0' + skill_level_str
                    if skill_level < 10:
                        skill_level_str = '0' + skill_level_str
                    url_str = get_person_icon(actor_json)
                    icon_url = remove_html(url_str)
                    index_str = \
                        skill_level_str + ';' + actor + ';' + \
                        actor_json['name'] + \
                        ';' + icon_url
                    if index_str not in results:
                        results.append(index_str)
        break
    if not instance_only:
        # search actor cache
        for subdir, _, files in os.walk(base_dir + '/cache/actors/'):
            for fname in files:
                if not fname.endswith('.json'):
                    continue
                if not is_account_dir(fname):
                    continue
                actor_filename = os.path.join(subdir, fname)
                cached_actor_json = load_json(actor_filename)
                if not cached_actor_json:
                    continue
                if cached_actor_json.get('actor'):
                    actor_json = cached_actor_json['actor']
                    if actor_json.get('id') and \
                       no_of_actor_skills(actor_json) > 0 and \
                       actor_json.get('name') and \
                       actor_json.get('icon'):
                        actor = actor_json['id']
                        actor_skills_list = \
                            actor_json['hasOccupation']['skills']
                        skills = get_skills_from_list(actor_skills_list)
                        for skill_name, skill_level in skills.items():
                            skill_name = skill_name.lower()
                            if not (skill_name in skillsearch or
                                    skillsearch in skill_name):
                                continue
                            skill_level_str = str(skill_level)
                            if skill_level < 100:
                                skill_level_str = '0' + skill_level_str
                            if skill_level < 10:
                                skill_level_str = '0' + skill_level_str
                            url_str = get_person_icon(actor_json)
                            icon_url = remove_html(url_str)
                            index_str = \
                                skill_level_str + ';' + actor + ';' + \
                                actor_json['name'] + \
                                ';' + icon_url
                            if index_str not in results:
                                results.append(index_str)
            break

    results.sort(reverse=True)

    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    skill_search_form = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    # show top banner
    if nickname and domain and theme_name:
        banner_file, _ = \
            get_banner_file(base_dir, nickname, domain, theme_name)
        skill_search_form += \
            '<header>\n' + \
            '<a href="/users/' + nickname + '/search" title="' + \
            translate['Search and follow'] + '" alt="' + \
            translate['Search and follow'] + '" ' + \
            'aria-flowto="containerHeader" tabindex="1" accesskey="' + \
            access_keys['menuSearch'] + '">\n'
        skill_search_form += \
            '<img loading="lazy" decoding="async" ' + \
            'class="timeline-banner" alt="" ' + \
            'src="/users/' + nickname + '/' + banner_file + '" /></a>\n' + \
            '</header>\n'

    skill_search_form += \
        '<center><h1><a href = "' + actor + '/search">' + \
        translate['Skills search'] + ': ' + \
        skillsearch + \
        '</a></h1></center>'

    if len(results) == 0:
        skill_search_form += \
            '<center><h5>' + translate['No results'] + \
            '</h5></center>'
    else:
        skill_search_form += '<center>'
        ctr = 0
        for skill_match in results:
            skill_match_fields = skill_match.split(';')
            if len(skill_match_fields) != 4:
                continue
            actor = skill_match_fields[1]
            actor_name = skill_match_fields[2]
            avatar_url = skill_match_fields[3]
            skill_search_form += \
                '<div class="search-result""><a href="' + \
                actor + '/skills">'
            skill_search_form += \
                '<img loading="lazy" decoding="async" src="' + avatar_url + \
                '" alt="" /><span class="search-result-text">' + actor_name + \
                '</span></a></div>'
            ctr += 1
            if ctr >= posts_per_page:
                break
        skill_search_form += '</center>'
    skill_search_form += html_footer()
    return skill_search_form


def html_history_search(translate: {}, base_dir: str,
                        http_prefix: str,
                        nickname: str, domain: str,
                        historysearch: str,
                        posts_per_page: int, page_number: int,
                        project_version: str,
                        recent_posts_cache: {},
                        max_recent_posts: int,
                        session,
                        cached_webfingers,
                        person_cache: {},
                        port: int,
                        yt_replace_domain: str,
                        twitter_replacement_domain: str,
                        show_published_date_only: bool,
                        peertube_instances: [],
                        allow_local_network_access: bool,
                        theme_name: str, box_name: str,
                        system_language: str,
                        max_like_count: int,
                        signing_priv_key_pem: str,
                        cw_lists: {},
                        lists_enabled: str,
                        timezone: str, bold_reading: bool,
                        dogwhistles: {}, access_keys: {},
                        min_images_for_accounts: [],
                        buy_sites: {},
                        auto_cw_cache: {},
                        mitm_servers: [],
                        instance_software: {}) -> str:
    """Show a page containing search results for your post history
    """
    if historysearch.startswith("'"):
        historysearch = historysearch[1:].strip()

    historysearch = historysearch.lower().strip('\n').strip('\r')

    box_filenames = \
        search_box_posts(base_dir, nickname, domain,
                         historysearch, posts_per_page, box_name)
    if box_name == 'outbox':
        box_filenames += \
            search_box_posts(base_dir, nickname, domain,
                             historysearch, posts_per_page, 'inbox')

    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    history_search_form = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    # add the page title
    domain_full = get_full_domain(domain, port)
    actor = local_actor_url(http_prefix, nickname, domain_full)
    history_search_title = '🔍 ' + translate['Your Posts']
    if box_name == 'bookmarks':
        history_search_title = '🔍 ' + translate['Bookmarks']

    if nickname and domain and theme_name:
        banner_file, _ = \
            get_banner_file(base_dir, nickname, domain, theme_name)
        history_search_form += \
            '<header>\n' + \
            '<a href="/users/' + nickname + '/search" title="' + \
            translate['Search and follow'] + '" alt="' + \
            translate['Search and follow'] + '" ' + \
            'aria-flowto="containerHeader" tabindex="1" accesskey="' + \
            access_keys['menuSearch'] + '">\n'
        history_search_form += \
            '<img loading="lazy" decoding="async" ' + \
            'class="timeline-banner" alt="" ' + \
            'src="/users/' + nickname + '/' + banner_file + '" /></a>\n' + \
            '</header>\n'

    history_search_form += \
        '<center><h1><a href="' + actor + '/search">' + \
        history_search_title + '</a></h1></center>'

    if len(box_filenames) == 0:
        history_search_form += \
            '<center><h5>' + translate['No results'] + \
            '</h5></center>'
        history_search_form += html_footer()
        return history_search_form

    separator_str = html_post_separator(base_dir, None)

    # ensure that the page number is in bounds
    if not page_number:
        page_number = 1
    elif page_number < 1:
        page_number = 1

    # get the start end end within the index file
    start_index = int((page_number - 1) * posts_per_page)
    end_index = start_index + posts_per_page
    no_of_box_filenames = len(box_filenames)
    if end_index >= no_of_box_filenames and no_of_box_filenames > 0:
        end_index = no_of_box_filenames - 1

    index = start_index
    minimize_all_images = False
    if nickname in min_images_for_accounts:
        minimize_all_images = True
    while index <= end_index:
        post_filename = box_filenames[index]
        if not post_filename:
            index += 1
            continue
        post_json_object = load_json(post_filename)
        if not post_json_object:
            index += 1
            continue
        show_individual_post_icons = True
        allow_deletion = False
        post_str = \
            individual_post_as_html(signing_priv_key_pem,
                                    True, recent_posts_cache,
                                    max_recent_posts,
                                    translate, None,
                                    base_dir, session, cached_webfingers,
                                    person_cache,
                                    nickname, domain, port,
                                    post_json_object,
                                    None, True, allow_deletion,
                                    http_prefix, project_version,
                                    'search',
                                    yt_replace_domain,
                                    twitter_replacement_domain,
                                    show_published_date_only,
                                    peertube_instances,
                                    allow_local_network_access,
                                    theme_name, system_language,
                                    max_like_count,
                                    show_individual_post_icons,
                                    show_individual_post_icons,
                                    False, False, False, False,
                                    cw_lists, lists_enabled,
                                    timezone, False, bold_reading,
                                    dogwhistles,
                                    minimize_all_images, None,
                                    buy_sites, auto_cw_cache,
                                    mitm_servers,
                                    instance_software)
        if post_str:
            history_search_form += separator_str + post_str
        index += 1

    history_search_form += html_footer()
    return history_search_form


def html_hashtag_search(nickname: str, domain: str, port: int,
                        recent_posts_cache: {}, max_recent_posts: int,
                        translate: {},
                        base_dir: str, hashtag: str, page_number: int,
                        posts_per_page: int,
                        session, cached_webfingers: {}, person_cache: {},
                        http_prefix: str, project_version: str,
                        yt_replace_domain: str,
                        twitter_replacement_domain: str,
                        show_published_date_only: bool,
                        peertube_instances: [],
                        allow_local_network_access: bool,
                        theme_name: str, system_language: str,
                        max_like_count: int,
                        signing_priv_key_pem: str,
                        cw_lists: {}, lists_enabled: str,
                        timezone: str, bold_reading: bool,
                        dogwhistles: {}, map_format: str,
                        access_keys: {}, box_name: str,
                        min_images_for_accounts: [],
                        buy_sites: {}, auto_cw_cache: {},
                        ua_str: str,
                        mitm_servers: [],
                        instance_software: {}) -> str:
    """Show a page containing search results for a hashtag
    or after selecting a hashtag from the swarm
    """
    if hashtag.startswith('#'):
        hashtag = hashtag[1:]
    hashtag = urllib.parse.unquote(hashtag)
    hashtag_index_file = base_dir + '/tags/' + hashtag + '.txt'
    if not os.path.isfile(hashtag_index_file):
        if hashtag != hashtag.lower():
            hashtag = hashtag.lower()
            hashtag_index_file = base_dir + '/tags/' + hashtag + '.txt'
    if not os.path.isfile(hashtag_index_file):
        print('WARN: hashtag file not found ' + hashtag_index_file)
        return None

    separator_str = html_post_separator(base_dir, None)

    # check that the directory for the nickname exists
    if nickname:
        account_dir = acct_dir(base_dir, nickname, domain)
        if not os.path.isdir(account_dir):
            nickname = None

    # read the index
    lines: list[str] = []
    try:
        with open(hashtag_index_file, 'r', encoding='utf-8') as fp_hash:
            lines = fp_hash.readlines()
    except OSError:
        print('EX: html_hashtag_search unable to read ' + hashtag_index_file)

    # read the css
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    # ensure that the page number is in bounds
    if not page_number:
        page_number = 1
    elif page_number < 1:
        page_number = 1

    # get the start end end within the index file
    start_index = int((page_number - 1) * posts_per_page)
    end_index = start_index + posts_per_page
    no_of_lines = len(lines)
    if end_index >= no_of_lines and no_of_lines > 0:
        end_index = no_of_lines - 1

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    hashtag_search_form = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    if nickname:
        # banner at top
        banner_file, _ = \
            get_banner_file(base_dir, nickname, domain, theme_name)
        hashtag_search_form += \
            '<header>\n' + \
            '<a href="/users/' + nickname + '/' + box_name + '" title="' + \
            translate['Search and follow'] + '" alt="' + \
            translate['Search and follow'] + '" ' + \
            'aria-flowto="containerHeader" tabindex="1" accesskey="' + \
            access_keys['menuSearch'] + '">\n'
        hashtag_search_form += '<img loading="lazy" decoding="async" ' + \
            'class="timeline-banner" alt="" ' + \
            'src="/users/' + nickname + '/' + banner_file + '" /></a>\n' + \
            '</header>\n'

        # add the page title
        hashtag_search_form += '<center>\n' + \
            '<h1><a href="/users/' + nickname + '/search">#' + \
            hashtag + '</a>'
    else:
        # add the page title
        hashtag_search_form += '<center>\n' + \
            '<h1>#' + hashtag

    # RSS link for hashtag feed
    hashtag_search_form += ' <a href="/tags/rss2/' + hashtag + '">'
    hashtag_search_form += \
        '<img style="width:3%;min-width:50px" ' + \
        'loading="lazy" decoding="async" ' + \
        'alt="RSS 2.0" title="RSS 2.0" src="/' + \
        'icons/logorss.png" /></a></h1>\n'

    # maps for geolocations with this hashtag
    maps_str = html_hashtag_maps(base_dir, hashtag, translate, map_format,
                                 nickname, domain, session, ua_str)
    if maps_str:
        maps_str = '<center>' + maps_str + '</center>\n'
    hashtag_search_form += maps_str

    # edit the category for this hashtag
    if is_editor(base_dir, nickname):
        category = get_hashtag_category(base_dir, hashtag)
        hashtag_search_form += '<div class="hashtagCategoryContainer">\n'
        hashtag_search_form += '  <form enctype="multipart/form-data" ' + \
            'method="POST" accept-charset="UTF-8" action="' + \
            '/users/' + nickname + '/tags/' + hashtag + \
            '/sethashtagcategory">\n'
        hashtag_search_form += '    <center>\n'
        hashtag_search_form += translate['Category']
        hashtag_search_form += \
            '      <input type="text" style="width: 20ch" ' + \
            'name="hashtagCategory" value="' + category + '">\n'
        hashtag_search_form += \
            '      <button type="submit" class="button" name="submitYes">' + \
            translate['Publish'] + '</button>\n'
        hashtag_search_form += '    </center>\n'
        hashtag_search_form += '  </form>\n'
        hashtag_search_form += '</div>\n'

    if start_index > 0:
        # previous page link
        hashtag_search_form += \
            '  <center>\n' + \
            '    <a href="/users/' + nickname + \
            '/tags/' + hashtag + '?page=' + \
            str(page_number - 1) + \
            '"><img loading="lazy" decoding="async" ' + \
            'class="pageicon" src="/' + \
            'icons/pageup.png" title="' + \
            translate['Page up'] + \
            '" alt="' + translate['Page up'] + \
            '"></a>\n  </center>\n'
    index = start_index
    text_mode_separator = '<div class="transparent"><hr></div>'
    while index <= end_index:
        post_id = lines[index].strip('\n').strip('\r')
        if '  ' not in post_id:
            nickname = get_nickname_from_actor(post_id)
            if not nickname:
                index += 1
                continue
        else:
            post_fields = post_id.split('  ')
            if len(post_fields) != 3:
                index += 1
                continue
            nickname = post_fields[1]
            post_id = post_fields[2]
        post_filename = locate_post(base_dir, nickname, domain, post_id)
        if not post_filename:
            index += 1
            continue
        post_json_object = load_json(post_filename)
        if not post_json_object:
            index += 1
            continue
        if not is_public_post(post_json_object):
            index += 1
            continue
        show_individual_post_icons = False
        if nickname:
            show_individual_post_icons = True
        allow_deletion = False
        show_repeats = show_individual_post_icons
        show_icons = show_individual_post_icons
        manually_approves_followers = False
        show_public_only = False
        store_to_sache = False
        allow_downloads = True
        avatar_url = None
        show_avatar_options = True
        minimize_all_images = False
        if nickname in min_images_for_accounts:
            minimize_all_images = True
        post_str = \
            individual_post_as_html(signing_priv_key_pem,
                                    allow_downloads, recent_posts_cache,
                                    max_recent_posts,
                                    translate, None,
                                    base_dir, session, cached_webfingers,
                                    person_cache,
                                    nickname, domain, port,
                                    post_json_object,
                                    avatar_url, show_avatar_options,
                                    allow_deletion,
                                    http_prefix, project_version,
                                    'search',
                                    yt_replace_domain,
                                    twitter_replacement_domain,
                                    show_published_date_only,
                                    peertube_instances,
                                    allow_local_network_access,
                                    theme_name, system_language,
                                    max_like_count,
                                    show_repeats, show_icons,
                                    manually_approves_followers,
                                    show_public_only,
                                    store_to_sache, False, cw_lists,
                                    lists_enabled, timezone, False,
                                    bold_reading, dogwhistles,
                                    minimize_all_images, None,
                                    buy_sites, auto_cw_cache,
                                    mitm_servers,
                                    instance_software)
        if post_str:
            hashtag_search_form += \
                text_mode_separator + separator_str + post_str
        index += 1

    hashtag_search_form += text_mode_separator

    if end_index < no_of_lines - 1:
        # next page link
        hashtag_search_form += \
            '  <center>\n' + \
            '    <a href="/users/' + nickname + '/tags/' + hashtag + \
            '?page=' + str(page_number + 1) + \
            '"><img loading="lazy" decoding="async" ' + \
            'class="pageicon" src="/icons' + \
            '/pagedown.png" title="' + translate['Page down'] + \
            '" alt="' + translate['Page down'] + '"></a>' + \
            '  </center>'
    hashtag_search_form += html_footer()
    return hashtag_search_form


def html_hashtag_search_remote(nickname: str, domain: str, port: int,
                               recent_posts_cache: {}, max_recent_posts: int,
                               translate: {},
                               base_dir: str, hashtag_url: str,
                               page_number: int, posts_per_page: int,
                               session, cached_webfingers: {},
                               person_cache: {},
                               http_prefix: str, project_version: str,
                               yt_replace_domain: str,
                               twitter_replacement_domain: str,
                               show_published_date_only: bool,
                               peertube_instances: [],
                               allow_local_network_access: bool,
                               theme_name: str, system_language: str,
                               max_like_count: int,
                               signing_priv_key_pem: str,
                               cw_lists: {}, lists_enabled: str,
                               timezone: str, bold_reading: bool,
                               dogwhistles: {},
                               min_images_for_accounts: [],
                               debug: bool, buy_sites: {},
                               auto_cw_cache: {},
                               mitm_servers: [],
                               instance_software: {}) -> str:
    """Show a page containing search results for a remote hashtag
    """
    hashtag = urllib.parse.unquote(hashtag_url.split('/')[-1])

    profile_str = 'https://www.w3.org/ns/activitystreams'
    as_header = {
        'Accept': 'application/activity+json; profile="' + profile_str + '"'
    }
    hashtag_url_with_page = hashtag_url
    if '?page=' not in hashtag_url_with_page:
        hashtag_url_with_page += '?page=' + str(page_number)
    hashtag_json = \
        get_json(signing_priv_key_pem,
                 session, hashtag_url_with_page, as_header, None, debug,
                 mitm_servers, __version__, http_prefix, domain)
    lines: list[str] = []
    if get_json_valid(hashtag_json):
        if 'orderedItems' in hashtag_json:
            lines = hashtag_json['orderedItems']
        else:
            print('No orderedItems in hashtag collection ' + str(hashtag_json))
    else:
        print('WARN: no hashtags returned for url ' + hashtag_url)
    if not lines:
        return ''

    separator_str = html_post_separator(base_dir, None)

    # check that the directory for the nickname exists
    if nickname:
        account_dir = acct_dir(base_dir, nickname, domain)
        if not os.path.isdir(account_dir):
            return None

    # read the css
    css_filename = base_dir + '/epicyon-profile.css'
    if os.path.isfile(base_dir + '/epicyon.css'):
        css_filename = base_dir + '/epicyon.css'

    # ensure that the page number is in bounds
    if not page_number:
        page_number = 1
    elif page_number < 1:
        page_number = 1

    instance_title = \
        get_config_param(base_dir, 'instanceTitle')
    preload_images: list[str] = []
    hashtag_search_form = \
        html_header_with_external_style(css_filename, instance_title, None,
                                        preload_images)

    # add the page title
    hashtag_search_form += '<center>\n' + \
        '<h1>#' + hashtag

    # RSS link for hashtag feed
    hashtag_rss = hashtag_url
    if '.html' in hashtag_rss:
        hashtag_rss = hashtag_rss.replace('.html', '')
    hashtag_search_form += ' <a href="' + hashtag_rss + '.rss">'
    hashtag_search_form += \
        '<img style="width:3%;min-width:50px" ' + \
        'loading="lazy" decoding="async" ' + \
        'alt="RSS 2.0" title="RSS 2.0" src="/' + \
        'icons/logorss.png" /></a></h1>\n'

    tag_link = '/users/' + nickname + '?remotetag=' + \
        hashtag_url.replace('/', '--')
    if page_number > 1 and hashtag_json.get('prev'):
        # previous page link
        hashtag_search_form += \
            '  <center>\n' + \
            '    <a href="' + tag_link + ';page=' + \
            str(page_number - 1) + \
            '"><img loading="lazy" decoding="async" ' + \
            'class="pageicon" src="/' + \
            'icons/pageup.png" title="' + \
            translate['Page up'] + \
            '" alt="' + translate['Page up'] + \
            '"></a>\n  </center>\n'
    text_mode_separator = '<div class="transparent"><hr></div>'
    post_ctr = 0
    for post_id in lines:
        print('Hashtag post_id ' + post_id)
        post_json_object = \
            get_json(signing_priv_key_pem,
                     session, post_id, as_header, None, debug,
                     mitm_servers, __version__, http_prefix, domain)
        if not get_json_valid(post_json_object):
            print('No hashtag post for ' + post_id)
            continue
        if not isinstance(post_json_object, dict):
            print('Hashtag post is not a dict ' + str(post_json_object))
            continue
        if not has_object_dict(post_json_object):
            if post_json_object.get('id') and \
               'to' in post_json_object and \
               'cc' in post_json_object:
                new_url = \
                    remove_id_ending(post_json_object['id'])
                actor = get_actor_from_post_id(new_url)
                new_post_json_object = {
                    "type": "Create",
                    "id": new_url + '/activity',
                    "to": post_json_object['to'],
                    "cc": post_json_object['cc'],
                    "actor": actor,
                    "object": post_json_object
                }
                post_json_object = new_post_json_object
            else:
                print('Hashtag post does not contain necessary fields ' +
                      str(post_json_object))
                continue
        if not is_public_post(post_json_object):
            print('Hashtag post is not public ' + post_id)
            continue

        # render harmless any dangerous markup
        harmless_markup(post_json_object)

        show_individual_post_icons = False
        allow_deletion = False
        show_repeats = show_individual_post_icons
        show_icons = show_individual_post_icons
        manually_approves_followers = False
        show_public_only = False
        store_to_sache = False
        allow_downloads = True
        avatar_url = None
        show_avatar_options = True
        minimize_all_images = False
        if nickname in min_images_for_accounts:
            minimize_all_images = True
        post_str = \
            individual_post_as_html(signing_priv_key_pem,
                                    allow_downloads, recent_posts_cache,
                                    max_recent_posts,
                                    translate, None,
                                    base_dir, session, cached_webfingers,
                                    person_cache,
                                    nickname, domain, port,
                                    post_json_object,
                                    avatar_url, show_avatar_options,
                                    allow_deletion,
                                    http_prefix, project_version,
                                    'search',
                                    yt_replace_domain,
                                    twitter_replacement_domain,
                                    show_published_date_only,
                                    peertube_instances,
                                    allow_local_network_access,
                                    theme_name, system_language,
                                    max_like_count,
                                    show_repeats, show_icons,
                                    manually_approves_followers,
                                    show_public_only,
                                    store_to_sache, False, cw_lists,
                                    lists_enabled, timezone, False,
                                    bold_reading, dogwhistles,
                                    minimize_all_images, None,
                                    buy_sites, auto_cw_cache,
                                    mitm_servers,
                                    instance_software)
        if post_str:
            hashtag_search_form += \
                text_mode_separator + separator_str + post_str
            post_ctr += 1
            if post_ctr >= posts_per_page:
                break

    hashtag_search_form += text_mode_separator

    if post_ctr >= 5 and hashtag_json.get('next'):
        # next page link
        hashtag_search_form += \
            '  <center>\n' + \
            '    <a href="' + tag_link + \
            ';page=' + str(page_number + 1) + \
            '"><img loading="lazy" decoding="async" ' + \
            'class="pageicon" src="/icons' + \
            '/pagedown.png" title="' + translate['Page down'] + \
            '" alt="' + translate['Page down'] + '"></a>' + \
            '  </center>'
    hashtag_search_form += html_footer()
    return hashtag_search_form


def hashtag_search_rss(nickname: str, domain: str, port: int,
                       base_dir: str, hashtag: str,
                       http_prefix: str,
                       system_language: str) -> str:
    """Show an rss feed for a hashtag
    """
    if hashtag.startswith('#'):
        hashtag = hashtag[1:]
    hashtag = urllib.parse.unquote(hashtag)
    hashtag_index_file = base_dir + '/tags/' + hashtag + '.txt'
    if not os.path.isfile(hashtag_index_file):
        if hashtag != hashtag.lower():
            hashtag = hashtag.lower()
            hashtag_index_file = base_dir + '/tags/' + hashtag + '.txt'
    if not os.path.isfile(hashtag_index_file):
        print('WARN: hashtag file not found ' + hashtag_index_file)
        return None

    # check that the directory for the nickname exists
    if nickname:
        account_dir = acct_dir(base_dir, nickname, domain)
        if not os.path.isdir(account_dir):
            nickname = None

    # read the index
    lines: list[str] = []
    try:
        with open(hashtag_index_file, 'r', encoding='utf-8') as fp_hash:
            lines = fp_hash.readlines()
    except OSError:
        print('EX: hashtag_search_rss unable to read ' + hashtag_index_file)
    if not lines:
        return None

    domain_full = get_full_domain(domain, port)

    max_feed_length = 10
    hashtag_feed = rss2tag_header(hashtag, http_prefix, domain_full)
    for index, _ in enumerate(lines):
        post_id = lines[index].strip('\n').strip('\r')
        if '  ' not in post_id:
            nickname = get_nickname_from_actor(post_id)
            if not nickname:
                index += 1
                if index >= max_feed_length:
                    break
                continue
        else:
            post_fields = post_id.split('  ')
            if len(post_fields) != 3:
                index += 1
                if index >= max_feed_length:
                    break
                continue
            nickname = post_fields[1]
            post_id = post_fields[2]
        post_filename = locate_post(base_dir, nickname, domain, post_id)
        if not post_filename:
            index += 1
            if index >= max_feed_length:
                break
            continue
        post_json_object = load_json(post_filename)
        if post_json_object:
            if not is_public_post(post_json_object):
                index += 1
                if index >= max_feed_length:
                    break
                continue
            # add to feed
            if 'content' in post_json_object['object'] and \
               post_json_object['object'].get('attributedTo') and \
               post_json_object['object'].get('published'):
                published = post_json_object['object']['published']
                pub_date = date_from_string_format(published,
                                                   ["%Y-%m-%dT%H:%M:%S%z"])
                rss_date_str = pub_date.strftime("%a, %d %b %Y %H:%M:%S UT")
                hashtag_feed += '     <item>'
                attrib_field = post_json_object['object']['attributedTo']
                attrib = get_attributed_to(attrib_field)
                if attrib:
                    hashtag_feed += \
                        '         <author>' + attrib + '</author>'
                if post_json_object['object'].get('summary'):
                    hashtag_feed += \
                        '         <title>' + \
                        escape_text(post_json_object['object']['summary']) + \
                        '</title>'
                description = \
                    get_base_content_from_post(post_json_object,
                                               system_language)
                description = first_paragraph_from_string(description)
                description = escape_text(description)
                hashtag_feed += \
                    '         <description>' + description + '</description>'
                hashtag_feed += \
                    '         <pubDate>' + rss_date_str + '</pubDate>'
                post_attachments = get_post_attachments(post_json_object)
                if post_attachments:
                    for attach in post_attachments:
                        if not attach.get('url'):
                            continue
                        url_str = get_url_from_post(attach['url'])
                        attach_url = remove_html(url_str)
                        hashtag_feed += \
                            '         <link>' + attach_url + '</link>'
                hashtag_feed += '     </item>'
        index += 1
        if index >= max_feed_length:
            break

    return hashtag_feed + rss2tag_footer()


def hashtag_search_json(nickname: str, domain: str, port: int,
                        base_dir: str, hashtag: str,
                        page_number: int, posts_per_page: int,
                        http_prefix: str) -> {}:
    """Show a json collection for a hashtag
    """
    if hashtag.startswith('#'):
        hashtag = hashtag[1:]
    hashtag = urllib.parse.unquote(hashtag)
    hashtag_index_file = base_dir + '/tags/' + hashtag + '.txt'
    if not os.path.isfile(hashtag_index_file):
        if hashtag != hashtag.lower():
            hashtag = hashtag.lower()
            hashtag_index_file = base_dir + '/tags/' + hashtag + '.txt'
    if not os.path.isfile(hashtag_index_file):
        print('WARN: hashtag file not found ' + hashtag_index_file)
        return None

    # check that the directory for the nickname exists
    if nickname:
        account_dir = acct_dir(base_dir, nickname, domain)
        if not os.path.isdir(account_dir):
            nickname = None

    # read the index
    lines: list[str] = []
    try:
        with open(hashtag_index_file, 'r', encoding='utf-8') as fp_hash:
            lines = fp_hash.readlines()
    except OSError:
        print('EX: hashtag_search_json unable to read ' +
              hashtag_index_file)
    if not lines:
        return None

    domain_full = get_full_domain(domain, port)

    url = http_prefix + '://' + domain_full + '/tags/' + \
        hashtag + '?page=' + str(page_number)
    hashtag_json = {
        "@context": [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1'
        ],
        'id': url,
        'orderedItems': [],
        'totalItems': 0,
        'type': 'OrderedCollection'
    }
    hashtag_json['first'] = \
        http_prefix + '://' + domain_full + '/tags/' + \
        hashtag + '?page=1'
    if page_number > 1:
        hashtag_json['prev'] = \
            http_prefix + '://' + domain_full + '/tags/' + \
            hashtag + '?page=' + str(page_number - 1)
    page_items = 0
    for index, _ in enumerate(lines):
        post_id = lines[index].strip('\n').strip('\r')
        if '  ' not in post_id:
            nickname = get_nickname_from_actor(post_id)
            if not nickname:
                continue
        else:
            post_fields = post_id.split('  ')
            if len(post_fields) != 3:
                continue
            nickname = post_fields[1]
            post_id = post_fields[2]
        post_filename = locate_post(base_dir, nickname, domain, post_id)
        if not post_filename:
            continue
        post_json_object = load_json(post_filename)
        if not post_json_object:
            continue
        if not has_object_dict(post_json_object):
            continue
        if not is_public_post(post_json_object):
            continue
        if not post_json_object['object'].get('id'):
            continue
        # add to feed
        page_items += 1
        if page_items < posts_per_page * (page_number - 1):
            continue
        id_str = remove_id_ending(post_json_object['object']['id'])
        hashtag_json['orderedItems'].append(id_str)
        hashtag_json['totalItems'] += 1
        if hashtag_json['totalItems'] >= posts_per_page:
            hashtag_json['next'] = \
                http_prefix + '://' + domain_full + '/tags/' + \
                hashtag + '?page=' + str(page_number + 1)
            break

    return hashtag_json
