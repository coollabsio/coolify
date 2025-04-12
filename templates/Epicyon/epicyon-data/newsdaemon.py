__filename__ = "newsdaemon.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface Columns"

# Example hashtag logic:
#
# if moderated and not #imcoxford then block
# if #pol and contains "westminster" then add #britpol
# if #unwantedtag then block

import os
import time
import html
from shutil import rmtree
from subprocess import Popen
from collections import OrderedDict
from newswire import get_dict_from_newswire
# from posts import send_signed_json
from posts import create_news_post
from posts import archive_posts_for_person
from utils import date_from_string_format
from utils import date_utcnow
from utils import valid_hash_tag
from utils import get_base_content_from_post
from utils import remove_html
from utils import get_full_domain
from utils import load_json
from utils import save_json
from utils import get_status_number
from utils import clear_from_post_caches
from utils import dangerous_markup
from utils import local_actor_url
from utils import text_in_file
from utils import data_dir
from session import create_session
from threads import begin_thread
from webapp_hashtagswarm import store_hash_tags


def _update_feeds_outbox_index(base_dir: str, domain: str,
                               post_id: str) -> None:
    """Updates the index used for imported RSS feeds
    """
    base_path = data_dir(base_dir) + '/news@' + domain
    index_filename = base_path + '/outbox.index'

    if os.path.isfile(index_filename):
        if not text_in_file(post_id, index_filename):
            try:
                with open(index_filename, 'r+',
                          encoding='utf-8') as fp_feeds:
                    content = fp_feeds.read()
                    if post_id + '\n' not in content:
                        fp_feeds.seek(0, 0)
                        fp_feeds.write(post_id + '\n' + content)
                        print('DEBUG: feeds post added to index')
            except OSError as ex:
                print('EX: Failed to write entry to feeds posts index ' +
                      index_filename + ' ' + str(ex))
        return

    try:
        with open(index_filename, 'w+', encoding='utf-8') as fp_feeds:
            fp_feeds.write(post_id + '\n')
    except OSError:
        print('EX: _update_feeds_outbox_index unable to write ' +
              index_filename)


def _save_arrived_time(post_filename: str, arrived: str) -> None:
    """Saves the time when an rss post arrived to a file
    """
    try:
        with open(post_filename + '.arrived', 'w+',
                  encoding='utf-8') as fp_arrived:
            fp_arrived.write(arrived)
    except OSError:
        print('EX: _save_arrived_time unable to write ' +
              post_filename + '.arrived')


def _remove_control_characters(content: str) -> str:
    """Remove escaped html
    """
    if '&' in content:
        return html.unescape(content)
    return content


def _hashtag_logical_not(tree: [], hashtags: [], moderated: bool,
                         content: str, url: str) -> bool:
    """ NOT
    """
    if len(tree) != 2:
        return False
    if isinstance(tree[1], str):
        return tree[1] not in hashtags
    if isinstance(tree[1], list):
        return not hashtag_rule_resolve(tree[1], hashtags,
                                        moderated, content, url)
    return False


def _hashtag_logical_contains(tree: [], content: str) -> bool:
    """ Contains
    """
    if len(tree) != 2:
        return False
    match_str = None
    if isinstance(tree[1], str):
        match_str = tree[1]
    elif isinstance(tree[1], list):
        match_str = tree[1][0]
    if match_str:
        if match_str.startswith('"') and match_str.endswith('"'):
            match_str = match_str[1:]
            match_str = match_str[:len(match_str) - 1]
        match_str_lower = match_str.lower()
        content_without_tags = content.replace('#' + match_str_lower, '')
        return match_str_lower in content_without_tags
    return False


def _hashtag_logical_from(tree: [], url: str) -> bool:
    """ FROM
    """
    if len(tree) != 2:
        return False
    match_str = None
    if isinstance(tree[1], str):
        match_str = tree[1]
    elif isinstance(tree[1], list):
        match_str = tree[1][0]
    if match_str:
        if match_str.startswith('"') and match_str.endswith('"'):
            match_str = match_str[1:]
            match_str = match_str[:len(match_str) - 1]
        return match_str.lower() in url
    return False


def _hashtag_logical_and(tree: [], hashtags: [], moderated: bool,
                         content: str, url: str) -> bool:
    """ AND
    """
    if len(tree) < 3:
        return False
    for arg_index in range(1, len(tree)):
        arg_value = False
        if isinstance(tree[arg_index], str):
            arg_value = tree[arg_index] in hashtags
        elif isinstance(tree[arg_index], list):
            arg_value = hashtag_rule_resolve(tree[arg_index],
                                             hashtags, moderated,
                                             content, url)
        if not arg_value:
            return False
    return True


def _hashtag_logical_or(tree: [], hashtags: [], moderated: bool,
                        content: str, url: str) -> bool:
    """ OR
    """
    if len(tree) < 3:
        return False
    for arg_index in range(1, len(tree)):
        arg_value = False
        if isinstance(tree[arg_index], str):
            arg_value = tree[arg_index] in hashtags
        elif isinstance(tree[arg_index], list):
            arg_value = hashtag_rule_resolve(tree[arg_index],
                                             hashtags, moderated,
                                             content, url)
        if arg_value:
            return True
    return False


def _hashtag_logical_xor(tree: [], hashtags: [], moderated: bool,
                         content: str, url: str) -> bool:
    """ XOR
    """
    if len(tree) < 3:
        return False
    true_ctr = 0
    for arg_index in range(1, len(tree)):
        arg_value = False
        if isinstance(tree[arg_index], str):
            arg_value = tree[arg_index] in hashtags
        elif isinstance(tree[arg_index], list):
            arg_value = hashtag_rule_resolve(tree[arg_index],
                                             hashtags, moderated,
                                             content, url)
        if arg_value:
            true_ctr += 1
    if true_ctr == 1:
        return True
    return False


def hashtag_rule_resolve(tree: [], hashtags: [], moderated: bool,
                         content: str, url: str) -> bool:
    """Returns whether the tree for a hashtag rule evaluates to true or false
    """
    if not tree:
        return False

    if tree[0] == 'not':
        return _hashtag_logical_not(tree, hashtags, moderated, content, url)
    if tree[0] == 'contains':
        return _hashtag_logical_contains(tree, content)
    if tree[0] == 'from':
        return _hashtag_logical_from(tree, url)
    if tree[0] == 'and':
        return _hashtag_logical_and(tree, hashtags, moderated, content, url)
    if tree[0] == 'or':
        return _hashtag_logical_or(tree, hashtags, moderated, content, url)
    if tree[0] == 'xor':
        return _hashtag_logical_xor(tree, hashtags, moderated, content, url)
    if tree[0].startswith('#') and len(tree) == 1:
        return tree[0] in hashtags
    if tree[0].startswith('moderated'):
        return moderated
    if tree[0].startswith('"') and tree[0].endswith('"'):
        return True

    return False


def hashtag_rule_tree(operators: [],
                      conditions_str: str,
                      tags_in_conditions: [],
                      moderated: bool) -> []:
    """Walks the tree
    """
    if not operators and conditions_str:
        conditions_str = conditions_str.strip()
        is_str = \
            conditions_str.startswith('"') and conditions_str.endswith('"')
        if conditions_str.startswith('#') or is_str or \
           conditions_str in operators or \
           conditions_str == 'moderated' or \
           conditions_str == 'contains':
            if conditions_str.startswith('#'):
                if conditions_str not in tags_in_conditions:
                    if ' ' not in conditions_str or \
                       conditions_str.startswith('"'):
                        tags_in_conditions.append(conditions_str)
            return [conditions_str.strip()]
        return None
    if not operators or not conditions_str:
        return None
    tree = None
    conditions_str = conditions_str.strip()
    is_str = conditions_str.startswith('"') and conditions_str.endswith('"')
    if conditions_str.startswith('#') or is_str or \
       conditions_str in operators or \
       conditions_str == 'moderated' or \
       conditions_str == 'contains':
        if conditions_str.startswith('#'):
            if conditions_str not in tags_in_conditions:
                if ' ' not in conditions_str or \
                   conditions_str.startswith('"'):
                    tags_in_conditions.append(conditions_str)
        tree = [conditions_str.strip()]
    ctr = 0
    while ctr < len(operators):
        oper = operators[ctr]
        opmatch = ' ' + oper + ' '
        if opmatch not in conditions_str and \
           not conditions_str.startswith(oper + ' '):
            ctr += 1
            continue
        tree = [oper]
        if opmatch in conditions_str:
            sections = conditions_str.split(opmatch)
        else:
            sections = conditions_str.split(oper + ' ', 1)
        for sub_condition_str in sections:
            result = hashtag_rule_tree(operators[ctr + 1:],
                                       sub_condition_str,
                                       tags_in_conditions, moderated)
            if result:
                tree.append(result)
        break
    return tree


def _hashtag_add(base_dir: str, http_prefix: str, domain_full: str,
                 post_json_object: {},
                 action_str: str, hashtags: [], system_language: str,
                 translate: {}, session) -> None:
    """Adds a hashtag via a hashtag rule
    """
    add_hashtag = action_str.split('add ', 1)[1].strip()
    if not add_hashtag.startswith('#'):
        return

    if add_hashtag not in hashtags:
        hashtags.append(add_hashtag)
    ht_id = add_hashtag.replace('#', '')
    if not valid_hash_tag(ht_id):
        return

    hashtag_url = http_prefix + "://" + domain_full + "/tags/" + ht_id
    new_tag = {
        'href': hashtag_url,
        'name': add_hashtag,
        'type': 'Hashtag'
    }
    # does the tag already exist?
    add_tag_object = None
    for htag in post_json_object['object']['tag']:
        if htag.get('type') and htag.get('name'):
            if htag['type'] == 'Hashtag' and \
               htag['name'] == add_hashtag:
                add_tag_object = htag
                break
    # append the tag if it wasn't found
    if not add_tag_object:
        post_json_object['object']['tag'].append(new_tag)
    # add corresponding html to the post content
    hashtag_html = \
        " <a href=\"" + hashtag_url + "\" class=\"addedHashtag\" " + \
        "rel=\"tag\">#<span>" + ht_id + "</span></a>"
    content = get_base_content_from_post(post_json_object, system_language)
    if hashtag_html in content:
        return

    if content.endswith('</p>'):
        content = \
            content[:len(content) - len('</p>')] + \
            hashtag_html + '</p>'
    else:
        content += hashtag_html
    post_json_object['object']['content'] = content
    domain = domain_full
    if ':' in domain:
        domain = domain.split(':')[0]
    store_hash_tags(base_dir, 'news', domain,
                    http_prefix, domain_full,
                    post_json_object, translate, session)


def _hashtag_remove(http_prefix: str, domain_full: str, post_json_object: {},
                    action_str: str, hashtags: [],
                    system_language: str) -> None:
    """Removes a hashtag via a hashtag rule
    """
    rm_hashtag = action_str.split('remove ', 1)[1].strip()
    if not rm_hashtag.startswith('#'):
        return

    if rm_hashtag in hashtags:
        hashtags.remove(rm_hashtag)
    ht_id = rm_hashtag.replace('#', '')
    hashtag_url = http_prefix + "://" + domain_full + "/tags/" + ht_id
    # remove tag html from the post content
    hashtag_html = \
        "<a href=\"" + hashtag_url + "\" class=\"addedHashtag\" " + \
        "rel=\"tag\">#<span>" + ht_id + "</span></a>"
    content = get_base_content_from_post(post_json_object, system_language)
    if hashtag_html in content:
        content = content.replace(hashtag_html, '').replace('  ', ' ')
        post_json_object['object']['content'] = content
        post_json_object['object']['contentMap'][system_language] = content
    rm_tag_object = None
    for htag in post_json_object['object']['tag']:
        if htag.get('type') and htag.get('name'):
            if htag['type'] == 'Hashtag' and \
               htag['name'] == rm_hashtag:
                rm_tag_object = htag
                break
    if rm_tag_object:
        post_json_object['object']['tag'].remove(rm_tag_object)


def _newswire_hashtag_processing(base_dir: str, post_json_object: {},
                                 hashtags: [], http_prefix: str,
                                 domain: str, port: int,
                                 moderated: bool, url: str,
                                 system_language: str,
                                 translate: {}, session) -> bool:
    """Applies hashtag rules to a news post.
    Returns true if the post should be saved to the news timeline
    of this instance
    """
    rules_filename = data_dir(base_dir) + '/hashtagrules.txt'
    if not os.path.isfile(rules_filename):
        return True
    rules: list[str] = []
    try:
        with open(rules_filename, 'r', encoding='utf-8') as fp_rules:
            rules = fp_rules.readlines()
    except OSError:
        print('EX: _newswire_hashtag_processing unable to read ' +
              rules_filename)

    domain_full = get_full_domain(domain, port)

    # get the full text content of the post
    content = ''
    if post_json_object['object'].get('content'):
        content += get_base_content_from_post(post_json_object,
                                              system_language)
    if post_json_object['object'].get('summary'):
        content += ' ' + post_json_object['object']['summary']
    content = content.lower()

    # actionOccurred = False
    operators = ('not', 'and', 'or', 'xor', 'from', 'contains')
    for rule_str in rules:
        if not rule_str:
            continue
        if not rule_str.startswith('if '):
            continue
        if ' then ' not in rule_str:
            continue
        conditions_str = rule_str.split('if ', 1)[1]
        conditions_str = conditions_str.split(' then ')[0]
        tags_in_conditions: list[str] = []
        tree = hashtag_rule_tree(operators, conditions_str,
                                 tags_in_conditions, moderated)
        if not hashtag_rule_resolve(tree, hashtags, moderated, content, url):
            continue
        # the condition matches, so do something
        action_str = rule_str.split(' then ')[1].strip()

        if action_str.startswith('add '):
            # add a hashtag
            _hashtag_add(base_dir, http_prefix, domain_full,
                         post_json_object, action_str, hashtags,
                         system_language, translate, session)
        elif action_str.startswith('remove '):
            # remove a hashtag
            _hashtag_remove(http_prefix, domain_full, post_json_object,
                            action_str, hashtags, system_language)
        elif action_str.startswith('block') or action_str.startswith('drop'):
            # Block this item
            return False
    return True


def _create_news_mirror(base_dir: str, domain: str,
                        post_id_number: str, url: str,
                        max_mirrored_articles: int) -> bool:
    """Creates a local mirror of a news article
    """
    if '|' in url or '>' in url:
        return True

    mirror_dir = data_dir(base_dir) + '/newsmirror'
    if not os.path.isdir(mirror_dir):
        os.mkdir(mirror_dir)

    # count the directories
    no_of_dirs = 0
    for _, dirs, _ in os.walk(mirror_dir):
        no_of_dirs = len(dirs)
        break

    mirror_index_filename = data_dir(base_dir) + '/newsmirror.txt'

    if max_mirrored_articles > 0 and no_of_dirs > max_mirrored_articles:
        if not os.path.isfile(mirror_index_filename):
            # no index for mirrors found
            return True
        removals: list[str] = []
        try:
            with open(mirror_index_filename, 'r',
                      encoding='utf-8') as fp_index:
                # remove the oldest directories
                ctr = 0
                while no_of_dirs > max_mirrored_articles:
                    ctr += 1
                    if ctr > 5000:
                        # escape valve
                        break

                    post_id = fp_index.readline()
                    if not post_id:
                        continue
                    post_id = post_id.strip()
                    mirror_article_dir = mirror_dir + '/' + post_id
                    if os.path.isdir(mirror_article_dir):
                        rmtree(mirror_article_dir,
                               ignore_errors=False, onexc=None)
                        removals.append(post_id)
                        no_of_dirs -= 1
        except OSError as exc:
            print('EX: _create_news_mirror unable to read ' +
                  mirror_index_filename + ' ' + str(exc))

        # remove the corresponding index entries
        if removals:
            index_content = ''
            try:
                with open(mirror_index_filename, 'r',
                          encoding='utf-8') as fp_index:
                    index_content = fp_index.read()
                    for remove_post_id in removals:
                        index_content = \
                            index_content.replace(remove_post_id + '\n', '')
            except OSError:
                print('EX: _create_news_mirror unable to read ' +
                      mirror_index_filename)
            try:
                with open(mirror_index_filename, 'w+',
                          encoding='utf-8') as fp_index:
                    fp_index.write(index_content)
            except OSError:
                print('EX: _create_news_mirror unable to write ' +
                      mirror_index_filename)

    mirror_article_dir = mirror_dir + '/' + post_id_number
    if os.path.isdir(mirror_article_dir):
        # already mirrored
        return True

    # for onion instances mirror via tor
    prefix_str = ''
    if domain.endswith('.onion'):
        prefix_str = '/usr/bin/torsocks '

    # download the files
    command_str = \
        prefix_str + '/usr/bin/wget -mkEpnp -e robots=off ' + url + \
        ' -P ' + mirror_article_dir
    proc = Popen(command_str, shell=True)
    os.waitpid(proc.pid, 0)

    if not os.path.isdir(mirror_article_dir):
        print('WARN: failed to mirror ' + url)
        return True

    # append the post Id number to the index file
    if os.path.isfile(mirror_index_filename):
        try:
            with open(mirror_index_filename, 'a+',
                      encoding='utf-8') as fp_index:
                fp_index.write(post_id_number + '\n')
        except OSError:
            print('EX: _create_news_mirror unable to append ' +
                  mirror_index_filename)
    else:
        try:
            with open(mirror_index_filename, 'w+',
                      encoding='utf-8') as fp_index:
                fp_index.write(post_id_number + '\n')
        except OSError:
            print('EX: _create_news_mirror unable to write ' +
                  mirror_index_filename)

    return True


def _convert_rss_to_activitypub(base_dir: str, http_prefix: str,
                                domain: str, port: int,
                                newswire: {},
                                translate: {},
                                recent_posts_cache: {},
                                max_mirrored_articles: int,
                                allow_local_network_access: bool,
                                system_language: str,
                                low_bandwidth: bool,
                                content_license_url: str,
                                media_license_url: str,
                                media_creator: str,
                                session) -> None:
    """Converts rss items in a newswire into posts
    """
    if not newswire:
        print('No newswire to convert')
        return

    base_path = data_dir(base_dir) + '/news@' + domain + '/outbox'
    if not os.path.isdir(base_path):
        os.mkdir(base_path)

    # oldest items first
    newswire_reverse = OrderedDict(sorted(newswire.items(), reverse=False))

    for date_str, item in newswire_reverse.items():
        original_date_str = date_str
        # convert the date to the format used by ActivityPub
        if '+00:00' in date_str:
            date_str = date_str.replace(' ', 'T')
            date_str = date_str.replace('+00:00', 'Z')
        else:
            try:
                date_str_with_offset = \
                    date_from_string_format(date_str, ["%Y-%m-%d %H:%M:%S%z"])
            except BaseException:
                print('EX: Newswire strptime failed ' + str(date_str))
                continue
            try:
                date_str = date_str_with_offset.strftime("%Y-%m-%dT%H:%M:%SZ")
            except BaseException:
                print('EX: Newswire date_str_with_offset failed ' +
                      str(date_str_with_offset))
                continue

        status_number, _ = get_status_number(date_str)
        new_post_id = \
            local_actor_url(http_prefix, 'news', domain) + \
            '/statuses/' + status_number

        # file where the post is stored
        filename = base_path + '/' + new_post_id.replace('/', '#') + '.json'
        if os.path.isfile(filename):
            # don't create the post if it already exists
            # set the url
            # newswire[original_date_str][1] = \
            #     '/users/news/statuses/' + status_number
            # set the filename
            newswire[original_date_str][3] = filename
            continue

        rss_title = _remove_control_characters(item[0])
        url = item[1]
        if dangerous_markup(url, allow_local_network_access, []) or \
           dangerous_markup(rss_title, allow_local_network_access, []):
            continue
        rss_description = ''

        # get the rss description if it exists
        rss_description = '<p>' + remove_html(item[4]) + '<p>'

        mirrored = item[7]
        post_url = url
        if mirrored and '://' in url:
            post_url = '/newsmirror/' + status_number + '/' + \
                url.split('://')[1]
            if post_url.endswith('/'):
                post_url += 'index.html'
            else:
                post_url += '/index.html'

        # add the off-site link to the description
        rss_description += \
            '<br><a href="' + post_url + '">' + \
            translate['Read more...'] + '</a>'

#        podcast_properties = None
#        if len(item) > 8:
#            podcast_properties = item[8]

        # NOTE: the id when the post is created will not be
        # consistent (it's based on the current time, not the
        # published time), so we change that later
        save_to_file = False
        attach_image_filename = None
        media_type = None
        image_description = None
        video_transcript = None
        city = 'London, England'
        conversation_id = None
        convthread_id = None
        languages_understood = [system_language]
        buy_url = ''
        chat_url = ''
        blog = create_news_post(base_dir,
                                domain, port, http_prefix,
                                rss_description,
                                save_to_file,
                                attach_image_filename, media_type,
                                image_description, video_transcript,
                                city, rss_title, system_language,
                                conversation_id, convthread_id, low_bandwidth,
                                content_license_url,
                                media_license_url, media_creator,
                                languages_understood, translate,
                                buy_url, chat_url, session)
        if not blog:
            continue

        if mirrored:
            if not _create_news_mirror(base_dir, domain, status_number,
                                       url, max_mirrored_articles):
                continue

        id_str = \
            local_actor_url(http_prefix, 'news', domain) + \
            '/statuses/' + status_number + '/replies'
        blog['news'] = True

        # note the time of arrival
        curr_time = date_utcnow()
        blog['object']['arrived'] = curr_time.strftime("%Y-%m-%dT%H:%M:%SZ")

        # change the id, based upon the published time
        blog['object']['replies']['id'] = id_str
        blog['object']['replies']['first']['partOf'] = id_str

        blog['id'] = new_post_id + '/activity'
        blog['object']['id'] = new_post_id
        blog['object']['atomUri'] = new_post_id
        blog['object']['url'] = \
            http_prefix + '://' + domain + '/@news/' + status_number
        blog['object']['published'] = date_str

        blog['object']['content'] = rss_description
        blog['object']['contentMap'][system_language] = rss_description

        domain_full = get_full_domain(domain, port)

        hashtags = item[6]

        post_id = new_post_id.replace('/', '#')

        moderated = item[5]

        save_post = \
            _newswire_hashtag_processing(base_dir, blog, hashtags,
                                         http_prefix, domain, port,
                                         moderated, url, system_language,
                                         translate, session)

        # save the post and update the index
        if save_post:
            # ensure that all hashtags are stored in the json
            # and appended to the content
            blog['object']['tag']: list[dict] = []
            for tag_name in hashtags:
                ht_id = tag_name.replace('#', '')
                hashtag_url = \
                    http_prefix + "://" + domain_full + "/tags/" + ht_id
                new_tag = {
                    'href': hashtag_url,
                    'name': tag_name,
                    'type': 'Hashtag'
                }
                blog['object']['tag'].append(new_tag)
                hashtag_html = \
                    " <a href=\"" + hashtag_url + \
                    "\" class=\"addedHashtag\" " + \
                    "rel=\"tag\">#<span>" + \
                    ht_id + "</span></a>"
                content = get_base_content_from_post(blog, system_language)
                if hashtag_html not in content:
                    if content.endswith('</p>'):
                        content = \
                            content[:len(content) - len('</p>')] + \
                            hashtag_html + '</p>'
                    else:
                        content += hashtag_html
                    blog['object']['content'] = content
                    blog['object']['contentMap'][system_language] = content

            # update the newswire tags if new ones have been found by
            # _newswire_hashtag_processing
            for tag in hashtags:
                if tag not in newswire[original_date_str][6]:
                    newswire[original_date_str][6].append(tag)

            store_hash_tags(base_dir, 'news', domain,
                            http_prefix, domain_full,
                            blog, translate, session)

            clear_from_post_caches(base_dir, recent_posts_cache, post_id)
            if save_json(blog, filename):
                _update_feeds_outbox_index(base_dir, domain, post_id + '.json')

                # Save a file containing the time when the post arrived
                # this can then later be used to construct the news timeline
                # excluding items during the voting period
                if moderated:
                    _save_arrived_time(filename,
                                       blog['object']['arrived'])
                else:
                    if os.path.isfile(filename + '.arrived'):
                        try:
                            os.remove(filename + '.arrived')
                        except OSError:
                            print('EX: _convert_rss_to_activitypub ' +
                                  'unable to delete ' + filename + '.arrived')

                # setting the url here links to the activitypub object
                # stored locally
                # newswire[original_date_str][1] = \
                #     '/users/news/statuses/' + status_number

                # set the filename
                newswire[original_date_str][3] = filename


def _merge_with_previous_newswire(old_newswire: {}, new_newswire: {}) -> None:
    """Preserve any votes or generated activitypub post filename
    as rss feeds are updated
    """
    if not old_newswire:
        return

    for published, fields in old_newswire.items():
        if not new_newswire.get(published):
            continue
        for i in range(1, 5):
            new_newswire[published][i] = fields[i]


def run_newswire_daemon(base_dir: str, httpd,
                        http_prefix: str, domain: str, port: int,
                        translate: {}) -> None:
    """Periodically updates RSS feeds
    """
    newswire_state_filename = data_dir(base_dir) + '/.newswirestate.json'
    refresh_filename = data_dir(base_dir) + '/.refresh_newswire'

    print('Starting newswire daemon')
    # initial sleep to allow the system to start up
    time.sleep(50)
    while True:
        # has the session been created yet?
        if not httpd.session:
            print('Newswire daemon waiting for session')
            httpd.session = create_session(httpd.proxy_type)
            if not httpd.session:
                print('Newswire daemon has no session')
                time.sleep(60)
                continue
            print('Newswire daemon session established')

        # try to update the feeds
        print('Updating newswire feeds')
        new_newswire = \
            get_dict_from_newswire(httpd.session, base_dir, domain,
                                   httpd.max_newswire_posts_per_source,
                                   httpd.max_newswire_feed_size_kb,
                                   httpd.maxTags,
                                   httpd.max_feed_item_size_kb,
                                   httpd.max_newswire_posts,
                                   httpd.maxCategoriesFeedItemSizeKb,
                                   httpd.system_language,
                                   httpd.debug,
                                   httpd.preferred_podcast_formats,
                                   httpd.rss_timeout_sec)

        if not httpd.newswire:
            print('Newswire feeds not updated')
            if os.path.isfile(newswire_state_filename):
                print('Loading newswire from file')
                httpd.newswire = load_json(newswire_state_filename)

        print('Merging with previous newswire')
        _merge_with_previous_newswire(httpd.newswire, new_newswire)

        httpd.newswire = new_newswire
        if new_newswire:
            save_json(httpd.newswire, newswire_state_filename)
            print('Newswire updated')
        else:
            print('No new newswire')

        print('Converting newswire to activitypub format')
        _convert_rss_to_activitypub(base_dir, http_prefix, domain, port,
                                    new_newswire, translate,
                                    httpd.recent_posts_cache,
                                    httpd.max_mirrored_articles,
                                    httpd.allow_local_network_access,
                                    httpd.system_language,
                                    httpd.low_bandwidth,
                                    httpd.content_license_url,
                                    httpd.content_license_url, '',
                                    httpd.session)
        print('Newswire feed converted to ActivityPub')

        if httpd.max_news_posts > 0:
            archive_dir = base_dir + '/archive'
            archive_subdir = \
                archive_dir + '/accounts/news@' + domain + '/outbox'
            print('Archiving news posts')
            archive_posts_for_person(http_prefix, 'news',
                                     domain, base_dir, 'outbox',
                                     archive_subdir,
                                     httpd.recent_posts_cache,
                                     httpd.max_news_posts)

        # wait a while before the next feeds update
        for _ in range(360):
            time.sleep(10)
            # if a new blog post has been created then stop
            # waiting and recalculate the newswire
            if not os.path.isfile(refresh_filename):
                continue
            try:
                os.remove(refresh_filename)
            except OSError:
                print('EX: run_newswire_daemon unable to delete ' +
                      str(refresh_filename))
            break


def run_newswire_watchdog(project_version: str, httpd) -> None:
    """This tries to keep the newswire update thread running even if it dies
    """
    print('THREAD: Starting newswire watchdog')
    newswire_original = \
        httpd.thrPostSchedule.clone(run_newswire_daemon)
    begin_thread(httpd.thrNewswireDaemon, 'run_newswire_watchdog')
    while True:
        time.sleep(50)
        if httpd.thrNewswireDaemon.is_alive():
            continue
        httpd.thrNewswireDaemon.kill()
        print('THREAD: restarting newswire watchdog')
        httpd.thrNewswireDaemon = \
            newswire_original.clone(run_newswire_daemon)
        begin_thread(httpd.thrNewswireDaemon, 'run_newswire_watchdog 2')
        print('Restarting newswire daemon...')
