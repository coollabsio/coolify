__filename__ = "daemon_post_receive.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon POST"

import os
import time
import copy
import errno
from socket import error as SocketError
from shares import add_share
from languages import get_understood_languages
from languages import set_default_post_language
from content import get_price_from_string
from content import replace_emoji_from_tags
from content import add_html_tags
from content import extract_text_fields_in_post
from content import extract_media_in_form_post
from content import save_media_in_form_post
from media import apply_watermark_to_image
from media import replace_twitter
from media import replace_you_tube
from media import process_meta_data
from media import convert_image_to_low_bandwidth
from media import attach_media
from city import get_spoofed_city
from flags import is_image_file
from flags import is_float
from utils import date_utcnow
from utils import date_from_string_format
from utils import set_searchable_by
from utils import get_instance_url
from utils import save_json
from utils import remove_post_from_cache
from utils import load_json
from utils import locate_post
from utils import refresh_newswire
from utils import get_base_content_from_post
from utils import license_link_from_name
from utils import get_config_param
from utils import acct_dir
from posts import create_reading_post
from posts import create_question_post
from posts import create_report_post
from posts import create_direct_message_post
from posts import create_followers_only_post
from posts import create_unlisted_post
from posts import create_blog_post
from posts import create_public_post
from posts import undo_pinned_post
from posts import pin_post2
from inbox import populate_replies
from inbox import update_edited_post
from daemon_utils import post_to_outbox
from webapp_column_right import html_citations
from httpheaders import set_headers
from httpcodes import write2
from cache import store_person_in_cache
from cache import remove_person_from_cache
from cache import get_person_from_cache
from shares import add_shares_to_actor
from person import get_actor_update_json

NEW_POST_SUCCESS = 1
NEW_POST_FAILED = -1
NEW_POST_CANCELLED = 2


def _receive_new_post_process_newpost(self, fields: {},
                                      base_dir: str, nickname: str,
                                      domain: str, domain_full: str, port: int,
                                      city: str, http_prefix: str,
                                      person_cache: {},
                                      content_license_url: str,
                                      mentions_str: str,
                                      comments_enabled: bool,
                                      filename: str,
                                      attachment_media_type: str,
                                      low_bandwidth: bool,
                                      translate: {},
                                      buy_url: str,
                                      chat_url: str,
                                      auto_cw_cache: {},
                                      edited_postid: str,
                                      edited_published: str,
                                      recent_posts_cache: {},
                                      max_mentions: int,
                                      max_emoji: int,
                                      allow_local_network_access: bool,
                                      debug: bool,
                                      system_language: str,
                                      signing_priv_key_pem: str,
                                      max_recent_posts: int,
                                      curr_session,
                                      cached_webfingers: {},
                                      allow_deletion: bool,
                                      yt_replace_domain: str,
                                      twitter_replacement_domain: str,
                                      show_published_date_only: bool,
                                      peertube_instances: [],
                                      theme_name: str,
                                      max_like_count: int,
                                      cw_lists: {},
                                      dogwhistles: {},
                                      min_images_for_accounts: {},
                                      max_hashtags: int,
                                      buy_sites: [],
                                      project_version: str,
                                      proxy_type: str,
                                      max_replies: int,
                                      onion_domain: str,
                                      i2p_domain: str,
                                      mitm_servers: [],
                                      instance_software: {}) -> int:
    """ A new post has been received from the New Post screen and
    is then sent to the outbox
    """
    if not fields.get('pinToProfile'):
        pin_to_profile = False
    else:
        pin_to_profile = True
        # is the post message empty?
        if not fields['message']:
            # remove the pinned content from profile screen
            undo_pinned_post(base_dir, nickname, domain)
            return NEW_POST_SUCCESS

    city = get_spoofed_city(city, base_dir, nickname, domain)

    conversation_id = None
    if fields.get('conversationId'):
        conversation_id = fields['conversationId']

    convthread_id = None
    if fields.get('convthreadId'):
        convthread_id = fields['convthreadId']

    languages_understood = \
        get_understood_languages(base_dir, http_prefix,
                                 nickname, domain_full,
                                 person_cache)

    media_license_url = content_license_url
    if fields.get('mediaLicense'):
        media_license_url = fields['mediaLicense']
        if '://' not in media_license_url:
            media_license_url = \
                license_link_from_name(media_license_url)
    media_creator = ''
    if fields.get('mediaCreator'):
        media_creator = fields['mediaCreator']
    video_transcript = ''
    if fields.get('videoTranscript'):
        video_transcript = fields['videoTranscript']
    if fields.get('searchableByDropdown'):
        set_searchable_by(base_dir, nickname, domain,
                          fields['searchableByDropdown'])
    message_json = \
        create_public_post(base_dir, nickname, domain,
                           port,
                           http_prefix,
                           mentions_str + fields['message'],
                           False, False, comments_enabled,
                           filename, attachment_media_type,
                           fields['imageDescription'],
                           video_transcript,
                           city,
                           fields['replyTo'], fields['replyTo'],
                           fields['subject'],
                           fields['schedulePost'],
                           fields['eventDate'],
                           fields['eventTime'],
                           fields['eventEndTime'],
                           fields['location'], False,
                           fields['languagesDropdown'],
                           conversation_id, convthread_id,
                           low_bandwidth,
                           content_license_url,
                           media_license_url, media_creator,
                           languages_understood,
                           translate, buy_url,
                           chat_url,
                           auto_cw_cache,
                           fields['searchableByDropdown'],
                           curr_session)
    if message_json:
        if edited_postid:
            update_edited_post(base_dir, nickname, domain,
                               message_json,
                               edited_published,
                               edited_postid,
                               recent_posts_cache,
                               'outbox',
                               max_mentions,
                               max_emoji,
                               allow_local_network_access,
                               debug,
                               system_language,
                               http_prefix,
                               domain_full,
                               person_cache,
                               signing_priv_key_pem,
                               max_recent_posts,
                               translate,
                               curr_session,
                               cached_webfingers,
                               port,
                               allow_deletion,
                               yt_replace_domain,
                               twitter_replacement_domain,
                               show_published_date_only,
                               peertube_instances,
                               theme_name,
                               max_like_count,
                               cw_lists,
                               dogwhistles,
                               min_images_for_accounts,
                               max_hashtags,
                               buy_sites,
                               auto_cw_cache,
                               onion_domain, i2p_domain,
                               mitm_servers,
                               instance_software)
            print('DEBUG: sending edited public post ' +
                  str(message_json))
        if fields['schedulePost']:
            return NEW_POST_SUCCESS
        if pin_to_profile:
            sys_language = system_language
            content_str = \
                get_base_content_from_post(message_json,
                                           sys_language)
            pin_post2(base_dir,
                      nickname, domain, content_str)
            return NEW_POST_SUCCESS
        if post_to_outbox(self, message_json,
                          project_version,
                          nickname,
                          curr_session, proxy_type):
            populate_replies(base_dir,
                             http_prefix,
                             domain_full,
                             message_json,
                             max_replies,
                             debug)
            return NEW_POST_SUCCESS
    return NEW_POST_FAILED


def _receive_new_post_process_newblog(self, fields: {},
                                      citations_button_press: bool,
                                      base_dir: str, nickname: str,
                                      newswire: {}, theme_name: str,
                                      domain: str, domain_full: str,
                                      port: int, translate: {},
                                      cookie: str, calling_domain: str,
                                      http_prefix: str, person_cache: {},
                                      content_license_url: str,
                                      comments_enabled: bool,
                                      filename: str,
                                      attachment_media_type: str,
                                      low_bandwidth: bool,
                                      buy_url: str, chat_url: str,
                                      project_version: str, curr_session,
                                      proxy_type: str, max_replies: int,
                                      debug: bool) -> int:
    """A new blog post has been received from the New Post screen and
    is then sent to the outbox
    """
    # citations button on newblog screen
    if citations_button_press:
        message_json = \
            html_citations(base_dir, nickname,
                           domain, translate,
                           newswire,
                           fields['subject'],
                           fields['message'],
                           theme_name)
        if message_json:
            message_json = message_json.encode('utf-8')
            message_json_len = len(message_json)
            set_headers(self, 'text/html',
                        message_json_len,
                        cookie, calling_domain, False)
            write2(self, message_json)
            return NEW_POST_SUCCESS
        return NEW_POST_FAILED
    if not fields['subject']:
        print('WARN: blog posts must have a title')
        return NEW_POST_FAILED
    if not fields['message']:
        print('WARN: blog posts must have content')
        return NEW_POST_FAILED
    # submit button on newblog screen
    save_to_file = False
    client_to_server = False
    city = None
    conversation_id = None
    if fields.get('conversationId'):
        conversation_id = fields['conversationId']
    convthread_id = None
    if fields.get('convthreadId'):
        convthread_id = fields['convthreadId']
    languages_understood = \
        get_understood_languages(base_dir, http_prefix,
                                 nickname, domain_full,
                                 person_cache)
    media_license_url = content_license_url
    if fields.get('mediaLicense'):
        media_license_url = fields['mediaLicense']
        if '://' not in media_license_url:
            media_license_url = \
                license_link_from_name(media_license_url)
    media_creator = ''
    if fields.get('mediaCreator'):
        media_creator = fields['mediaCreator']
    video_transcript = ''
    if fields.get('videoTranscript'):
        video_transcript = fields['videoTranscript']
    if fields.get('searchableByDropdown'):
        set_searchable_by(base_dir, nickname, domain,
                          fields['searchableByDropdown'])
    message_json = \
        create_blog_post(base_dir, nickname,
                         domain, port, http_prefix,
                         fields['message'], save_to_file,
                         client_to_server, comments_enabled,
                         filename, attachment_media_type,
                         fields['imageDescription'],
                         video_transcript, city,
                         fields['replyTo'], fields['replyTo'],
                         fields['subject'],
                         fields['schedulePost'],
                         fields['eventDate'],
                         fields['eventTime'],
                         fields['eventEndTime'],
                         fields['location'],
                         fields['languagesDropdown'],
                         conversation_id, convthread_id,
                         low_bandwidth,
                         content_license_url,
                         media_license_url, media_creator,
                         languages_understood,
                         translate, buy_url, chat_url,
                         fields['searchableByDropdown'],
                         curr_session)
    if message_json:
        if fields['schedulePost']:
            return NEW_POST_SUCCESS
        if post_to_outbox(self, message_json,
                          project_version,
                          nickname,
                          curr_session, proxy_type):
            refresh_newswire(base_dir)
            populate_replies(base_dir, http_prefix, domain_full,
                             message_json,
                             max_replies,
                             debug)
            return NEW_POST_SUCCESS
    return NEW_POST_FAILED


def _receive_new_post_process_editblog(self, fields: {},
                                       base_dir: str,
                                       nickname: str, domain: str,
                                       recent_posts_cache: {},
                                       http_prefix: str, translate: {},
                                       curr_session, debug: bool,
                                       system_language: str,
                                       port: int, filename: str,
                                       city: str, content_license_url: str,
                                       attachment_media_type: str,
                                       low_bandwidth: bool,
                                       yt_replace_domain: str,
                                       twitter_replacement_domain: str) -> int:
    """Edited blog post has been received and is then sent to the outbox
    """
    print('Edited blog post received')
    post_filename = \
        locate_post(base_dir, nickname, domain, fields['postUrl'])
    if os.path.isfile(post_filename):
        post_json_object = load_json(post_filename)
        if post_json_object:
            cached_filename = \
                acct_dir(base_dir, nickname, domain) + \
                '/postcache/' + \
                fields['postUrl'].replace('/', '#') + '.html'
            if os.path.isfile(cached_filename):
                print('Edited blog post, removing cached html')
                try:
                    os.remove(cached_filename)
                except OSError:
                    print('EX: _receive_new_post_process ' +
                          'unable to delete ' + cached_filename)
            # remove from memory cache
            remove_post_from_cache(post_json_object,
                                   recent_posts_cache)
            # change the blog post title
            post_json_object['object']['summary'] = \
                fields['subject']
            # format message
            tags: list[dict] = []
            hashtags_dict = {}
            mentioned_recipients: list[str] = []
            fields['message'] = \
                add_html_tags(base_dir, http_prefix,
                              nickname, domain,
                              fields['message'],
                              mentioned_recipients,
                              hashtags_dict,
                              translate, True)
            # replace emoji with unicode
            tags: list[dict] = []
            for _, tag in hashtags_dict.items():
                tags.append(tag)
            # get list of tags
            fields['message'] = \
                replace_emoji_from_tags(curr_session,
                                        base_dir,
                                        fields['message'],
                                        tags, 'content',
                                        debug, True)

            post_json_object['object']['content'] = \
                fields['message']
            content_map = post_json_object['object']['contentMap']
            content_map[system_language] = \
                fields['message']

            img_description = ''
            if fields.get('imageDescription'):
                img_description = fields['imageDescription']
            video_transcript = ''
            if fields.get('videoTranscript'):
                video_transcript = fields['videoTranscript']

            if filename:
                city = get_spoofed_city(city, base_dir, nickname,
                                        domain)
                license_url = content_license_url
                if fields.get('mediaLicense'):
                    license_url = fields['mediaLicense']
                    if '://' not in license_url:
                        license_url = \
                            license_link_from_name(license_url)
                creator = ''
                if fields.get('mediaCreator'):
                    creator = fields['mediaCreator']
                post_json_object['object'] = \
                    attach_media(base_dir,
                                 http_prefix, nickname,
                                 domain, port,
                                 post_json_object['object'],
                                 filename,
                                 attachment_media_type,
                                 img_description,
                                 video_transcript,
                                 city, low_bandwidth,
                                 license_url, creator,
                                 fields['languagesDropdown'])

            replace_you_tube(post_json_object,
                             yt_replace_domain,
                             system_language)
            replace_twitter(post_json_object,
                            twitter_replacement_domain,
                            system_language)
            save_json(post_json_object, post_filename)
            # also save to the news actor
            if nickname != 'news':
                post_filename = \
                    post_filename.replace('#users#' +
                                          nickname + '#',
                                          '#users#news#')
                save_json(post_json_object, post_filename)
            print('Edited blog post, resaved ' + post_filename)
            return NEW_POST_SUCCESS
        print('Edited blog post, unable to load json for ' +
              post_filename)
    else:
        print('Edited blog post not found ' +
              str(fields['postUrl']))
    return NEW_POST_FAILED


def _receive_new_post_process_newunlisted(self, fields: {},
                                          city: str, base_dir: str,
                                          nickname: str, domain: str,
                                          domain_full: str,
                                          http_prefix: str,
                                          person_cache: {},
                                          content_license_url: str,
                                          port: int, mentions_str: str,
                                          comments_enabled: bool,
                                          filename: str,
                                          attachment_media_type: str,
                                          low_bandwidth: bool,
                                          translate: {}, buy_url: str,
                                          chat_url: str,
                                          auto_cw_cache: {},
                                          edited_postid: str,
                                          edited_published: str,
                                          recent_posts_cache: {},
                                          max_mentions: int,
                                          max_emoji: int,
                                          allow_local_network_access: bool,
                                          debug: bool,
                                          system_language: str,
                                          signing_priv_key_pem: str,
                                          max_recent_posts: int,
                                          curr_session,
                                          cached_webfingers: {},
                                          allow_deletion: bool,
                                          yt_replace_domain: str,
                                          twitter_replacement_domain: str,
                                          show_published_date_only: bool,
                                          peertube_instances: [],
                                          theme_name: str,
                                          max_like_count: int,
                                          cw_lists: {},
                                          dogwhistles: {},
                                          min_images_for_accounts: {},
                                          max_hashtags: int,
                                          buy_sites: [],
                                          project_version: str,
                                          proxy_type: str,
                                          max_replies: int,
                                          onion_domain: str,
                                          i2p_domain: str,
                                          mitm_servers: [],
                                          instance_software: {}) -> int:
    """Unlisted post has been received from New Post screen
    and is then sent to the outbox
    """
    city = get_spoofed_city(city, base_dir, nickname, domain)
    save_to_file = False
    client_to_server = False

    conversation_id = None
    if fields.get('conversationId'):
        conversation_id = fields['conversationId']

    convthread_id = None
    if fields.get('convthreadId'):
        convthread_id = fields['convthreadId']

    languages_understood = \
        get_understood_languages(base_dir, http_prefix, nickname,
                                 domain_full, person_cache)
    media_license_url = content_license_url
    if fields.get('mediaLicense'):
        media_license_url = fields['mediaLicense']
        if '://' not in media_license_url:
            media_license_url = \
                license_link_from_name(media_license_url)
    media_creator = ''
    if fields.get('mediaCreator'):
        media_creator = fields['mediaCreator']
    video_transcript = ''
    if fields.get('videoTranscript'):
        video_transcript = fields['videoTranscript']
    message_json = \
        create_unlisted_post(base_dir, nickname, domain, port,
                             http_prefix,
                             mentions_str + fields['message'],
                             save_to_file,
                             client_to_server, comments_enabled,
                             filename, attachment_media_type,
                             fields['imageDescription'],
                             video_transcript,
                             city,
                             fields['replyTo'],
                             fields['replyTo'],
                             fields['subject'],
                             fields['schedulePost'],
                             fields['eventDate'],
                             fields['eventTime'],
                             fields['eventEndTime'],
                             fields['location'],
                             fields['languagesDropdown'],
                             conversation_id, convthread_id,
                             low_bandwidth,
                             content_license_url,
                             media_license_url, media_creator,
                             languages_understood,
                             translate, buy_url,
                             chat_url,
                             auto_cw_cache, curr_session)
    if message_json:
        if edited_postid:
            update_edited_post(base_dir, nickname, domain,
                               message_json,
                               edited_published,
                               edited_postid,
                               recent_posts_cache,
                               'outbox',
                               max_mentions,
                               max_emoji,
                               allow_local_network_access,
                               debug,
                               system_language,
                               http_prefix,
                               domain_full,
                               person_cache,
                               signing_priv_key_pem,
                               max_recent_posts,
                               translate,
                               curr_session,
                               cached_webfingers,
                               port,
                               allow_deletion,
                               yt_replace_domain,
                               twitter_replacement_domain,
                               show_published_date_only,
                               peertube_instances,
                               theme_name,
                               max_like_count,
                               cw_lists,
                               dogwhistles,
                               min_images_for_accounts,
                               max_hashtags,
                               buy_sites,
                               auto_cw_cache,
                               onion_domain, i2p_domain,
                               mitm_servers,
                               instance_software)
            print('DEBUG: sending edited unlisted post ' +
                  str(message_json))

        if fields['schedulePost']:
            return NEW_POST_SUCCESS
        if post_to_outbox(self, message_json,
                          project_version,
                          nickname,
                          curr_session, proxy_type):
            populate_replies(base_dir, http_prefix, domain,
                             message_json,
                             max_replies,
                             debug)
            return NEW_POST_SUCCESS
    return NEW_POST_FAILED


def _receive_new_post_process_newfollowers(self, fields: {},
                                           city: str, base_dir: str,
                                           nickname: str, domain: str,
                                           domain_full: str,
                                           mentions_str: str,
                                           http_prefix: str,
                                           person_cache: {},
                                           content_license_url: str,
                                           port: int, comments_enabled: bool,
                                           filename: str,
                                           attachment_media_type: str,
                                           low_bandwidth: bool,
                                           translate: {},
                                           buy_url: str, chat_url: str,
                                           auto_cw_cache: {},
                                           edited_postid: str,
                                           edited_published: str,
                                           recent_posts_cache: {},
                                           max_mentions: int,
                                           max_emoji: int,
                                           allow_local_network_access: bool,
                                           debug: bool,
                                           system_language: str,
                                           signing_priv_key_pem: str,
                                           max_recent_posts: int,
                                           curr_session,
                                           cached_webfingers: {},
                                           allow_deletion: bool,
                                           yt_replace_domain: str,
                                           twitter_replacement_domain: str,
                                           show_published_date_only: bool,
                                           peertube_instances: [],
                                           theme_name: str,
                                           max_like_count: int,
                                           cw_lists: {},
                                           dogwhistles: {},
                                           min_images_for_accounts: {},
                                           max_hashtags: int,
                                           buy_sites: [],
                                           project_version: str,
                                           proxy_type: str,
                                           max_replies: int,
                                           onion_domain: str,
                                           i2p_domain: str,
                                           mitm_servers: [],
                                           instance_software: {}) -> int:
    """Followers only post has been received from New Post screen
    and is then sent to the outbox
    """
    city = get_spoofed_city(city, base_dir, nickname, domain)
    save_to_file = False
    client_to_server = False

    conversation_id = None
    if fields.get('conversationId'):
        conversation_id = fields['conversationId']

    convthread_id = None
    if fields.get('convthreadId'):
        convthread_id = fields['convthreadId']

    mentions_message = mentions_str + fields['message']
    languages_understood = \
        get_understood_languages(base_dir, http_prefix,
                                 nickname, domain_full,
                                 person_cache)
    media_license_url = content_license_url
    if fields.get('mediaLicense'):
        media_license_url = fields['mediaLicense']
        if '://' not in media_license_url:
            media_license_url = \
                license_link_from_name(media_license_url)
    media_creator = ''
    if fields.get('mediaCreator'):
        media_creator = fields['mediaCreator']
    video_transcript = ''
    if fields.get('videoTranscript'):
        video_transcript = fields['videoTranscript']
    if fields.get('searchableByDropdown'):
        set_searchable_by(base_dir, nickname, domain,
                          fields['searchableByDropdown'])
    message_json = \
        create_followers_only_post(base_dir, nickname, domain,
                                   port, http_prefix,
                                   mentions_message,
                                   save_to_file,
                                   client_to_server,
                                   comments_enabled,
                                   filename, attachment_media_type,
                                   fields['imageDescription'],
                                   video_transcript,
                                   city,
                                   fields['replyTo'],
                                   fields['replyTo'],
                                   fields['subject'],
                                   fields['schedulePost'],
                                   fields['eventDate'],
                                   fields['eventTime'],
                                   fields['eventEndTime'],
                                   fields['location'],
                                   fields['languagesDropdown'],
                                   conversation_id, convthread_id,
                                   low_bandwidth,
                                   content_license_url,
                                   media_license_url,
                                   media_creator,
                                   languages_understood,
                                   translate,
                                   buy_url, chat_url,
                                   auto_cw_cache,
                                   fields['searchableByDropdown'],
                                   curr_session)
    if message_json:
        if edited_postid:
            update_edited_post(base_dir,
                               nickname, domain,
                               message_json,
                               edited_published,
                               edited_postid,
                               recent_posts_cache,
                               'outbox',
                               max_mentions,
                               max_emoji,
                               allow_local_network_access,
                               debug,
                               system_language,
                               http_prefix,
                               domain_full,
                               person_cache,
                               signing_priv_key_pem,
                               max_recent_posts,
                               translate,
                               curr_session,
                               cached_webfingers,
                               port,
                               allow_deletion,
                               yt_replace_domain,
                               twitter_replacement_domain,
                               show_published_date_only,
                               peertube_instances,
                               theme_name,
                               max_like_count,
                               cw_lists,
                               dogwhistles,
                               min_images_for_accounts,
                               max_hashtags,
                               buy_sites,
                               auto_cw_cache,
                               onion_domain, i2p_domain,
                               mitm_servers,
                               instance_software)
            print('DEBUG: sending edited followers post ' +
                  str(message_json))

        if fields['schedulePost']:
            return NEW_POST_SUCCESS
        if post_to_outbox(self, message_json,
                          project_version,
                          nickname,
                          curr_session, proxy_type):
            populate_replies(base_dir, http_prefix, domain,
                             message_json,
                             max_replies,
                             debug)
            return NEW_POST_SUCCESS
    return NEW_POST_FAILED


def _receive_new_post_process_newdm(self, fields: {},
                                    mentions_str: str,
                                    city: str, base_dir: str,
                                    nickname: str, domain: str,
                                    domain_full: str,
                                    http_prefix: str,
                                    person_cache: {},
                                    content_license_url: str,
                                    port: int, comments_enabled: str,
                                    filename: str,
                                    attachment_media_type: str,
                                    low_bandwidth: bool,
                                    dm_license_url: str,
                                    translate: {},
                                    buy_url: str, chat_url: str,
                                    auto_cw_cache: {},
                                    edited_postid: str,
                                    edited_published: str,
                                    recent_posts_cache: {},
                                    max_mentions: int,
                                    max_emoji: int,
                                    allow_local_network_access: bool,
                                    debug: bool,
                                    system_language: str,
                                    signing_priv_key_pem: str,
                                    max_recent_posts: int,
                                    curr_session,
                                    cached_webfingers: {},
                                    allow_deletion: bool,
                                    yt_replace_domain: str,
                                    twitter_replacement_domain: str,
                                    show_published_date_only: bool,
                                    peertube_instances: [],
                                    theme_name: str,
                                    max_like_count: int,
                                    cw_lists: {},
                                    dogwhistles: {},
                                    min_images_for_accounts: {},
                                    max_hashtags: int,
                                    buy_sites: [],
                                    project_version: str,
                                    proxy_type: str,
                                    max_replies: int,
                                    onion_domain: str,
                                    i2p_domain: str,
                                    mitm_servers: [],
                                    instance_software: {}) -> int:
    """Direct message post has been received from New Post screen
    and is then sent to the outbox
    """
    message_json = None
    print('A DM was posted')
    if '@' in mentions_str:
        city = get_spoofed_city(city, base_dir, nickname, domain)
        save_to_file = False
        client_to_server = False

        conversation_id = None
        if fields.get('conversationId'):
            conversation_id = fields['conversationId']

        convthread_id = None
        if fields.get('convthreadId'):
            convthread_id = fields['convthreadId']

        languages_understood = \
            get_understood_languages(base_dir, http_prefix,
                                     nickname, domain_full,
                                     person_cache)

        reply_is_chat = False
        if fields.get('replychatmsg'):
            reply_is_chat = fields['replychatmsg']

        media_license_url = content_license_url
        if fields.get('mediaLicense'):
            media_license_url = fields['mediaLicense']
            if '://' not in media_license_url:
                media_license_url = \
                    license_link_from_name(media_license_url)
        media_creator = ''
        if fields.get('mediaCreator'):
            media_creator = fields['mediaCreator']
        video_transcript = ''
        if fields.get('videoTranscript'):
            video_transcript = fields['videoTranscript']
        message_json = \
            create_direct_message_post(base_dir, nickname, domain,
                                       port, http_prefix,
                                       mentions_str +
                                       fields['message'],
                                       save_to_file,
                                       client_to_server,
                                       comments_enabled,
                                       filename,
                                       attachment_media_type,
                                       fields['imageDescription'],
                                       video_transcript,
                                       city,
                                       fields['replyTo'],
                                       fields['replyTo'],
                                       fields['subject'],
                                       True,
                                       fields['schedulePost'],
                                       fields['eventDate'],
                                       fields['eventTime'],
                                       fields['eventEndTime'],
                                       fields['location'],
                                       fields['languagesDropdown'],
                                       conversation_id, convthread_id,
                                       low_bandwidth,
                                       dm_license_url,
                                       media_license_url,
                                       media_creator,
                                       languages_understood,
                                       reply_is_chat,
                                       translate,
                                       buy_url, chat_url,
                                       auto_cw_cache, curr_session)
    if message_json:
        print('DEBUG: posting DM edited_postid ' +
              str(edited_postid))
        if edited_postid:
            update_edited_post(base_dir, nickname, domain,
                               message_json,
                               edited_published,
                               edited_postid,
                               recent_posts_cache,
                               'outbox',
                               max_mentions,
                               max_emoji,
                               allow_local_network_access,
                               debug,
                               system_language,
                               http_prefix,
                               domain_full,
                               person_cache,
                               signing_priv_key_pem,
                               max_recent_posts,
                               translate,
                               curr_session,
                               cached_webfingers,
                               port,
                               allow_deletion,
                               yt_replace_domain,
                               twitter_replacement_domain,
                               show_published_date_only,
                               peertube_instances,
                               theme_name,
                               max_like_count,
                               cw_lists,
                               dogwhistles,
                               min_images_for_accounts,
                               max_hashtags,
                               buy_sites,
                               auto_cw_cache,
                               onion_domain, i2p_domain,
                               mitm_servers,
                               instance_software)
            print('DEBUG: sending edited dm post ' +
                  str(message_json))

        if fields['schedulePost']:
            return NEW_POST_SUCCESS
        print('Sending new DM to ' +
              str(message_json['object']['to']))
        if post_to_outbox(self, message_json,
                          project_version,
                          nickname,
                          curr_session, proxy_type):
            populate_replies(base_dir, http_prefix, domain,
                             message_json,
                             max_replies,
                             debug)
            return NEW_POST_SUCCESS
    return NEW_POST_FAILED


def _receive_new_post_process_newreminder(self, fields: {}, nickname: str,
                                          domain: str, domain_full: str,
                                          mentions_str: str,
                                          city: str, base_dir: str,
                                          http_prefix: str,
                                          person_cache: {},
                                          port: int,
                                          filename: str,
                                          attachment_media_type: str,
                                          low_bandwidth: bool,
                                          dm_license_url: str,
                                          translate: {},
                                          buy_url: str, chat_url: str,
                                          auto_cw_cache: {},
                                          edited_postid: str,
                                          edited_published: str,
                                          recent_posts_cache: {},
                                          max_mentions: int,
                                          max_emoji: int,
                                          allow_local_network_access: bool,
                                          debug: bool,
                                          system_language: str,
                                          signing_priv_key_pem: str,
                                          max_recent_posts: int,
                                          curr_session,
                                          cached_webfingers: {},
                                          allow_deletion: bool,
                                          yt_replace_domain: str,
                                          twitter_replacement_domain: str,
                                          show_published_date_only: bool,
                                          peertube_instances: [],
                                          theme_name: str,
                                          max_like_count: int,
                                          cw_lists: {},
                                          dogwhistles: {},
                                          min_images_for_accounts: {},
                                          max_hashtags: int,
                                          buy_sites: [],
                                          project_version: str,
                                          proxy_type: str,
                                          onion_domain: str,
                                          i2p_domain: str,
                                          mitm_servers: [],
                                          instance_software: {}) -> int:
    """Reminder post has been received from New Post screen
    and is then sent to the outbox
    """
    message_json = None
    handle = nickname + '@' + domain_full
    print('A reminder was posted for ' + handle)
    if '@' + handle not in mentions_str:
        mentions_str = '@' + handle + ' ' + mentions_str
    city = get_spoofed_city(city, base_dir, nickname, domain)
    save_to_file = False
    client_to_server = False
    comments_enabled = False
    conversation_id = None
    convthread_id = None
    mentions_message = mentions_str + fields['message']
    languages_understood = \
        get_understood_languages(base_dir, http_prefix,
                                 nickname, domain_full,
                                 person_cache)
    media_license_url = ''
    media_creator = ''
    if fields.get('mediaCreator'):
        media_creator = fields['mediaCreator']
    video_transcript = ''
    if fields.get('videoTranscript'):
        video_transcript = fields['videoTranscript']
    message_json = \
        create_direct_message_post(base_dir, nickname, domain,
                                   port, http_prefix,
                                   mentions_message,
                                   save_to_file,
                                   client_to_server,
                                   comments_enabled,
                                   filename, attachment_media_type,
                                   fields['imageDescription'],
                                   video_transcript,
                                   city,
                                   None, None,
                                   fields['subject'],
                                   True, fields['schedulePost'],
                                   fields['eventDate'],
                                   fields['eventTime'],
                                   fields['eventEndTime'],
                                   fields['location'],
                                   fields['languagesDropdown'],
                                   conversation_id, convthread_id,
                                   low_bandwidth,
                                   dm_license_url,
                                   media_license_url,
                                   media_creator,
                                   languages_understood,
                                   False, translate,
                                   buy_url, chat_url,
                                   auto_cw_cache, curr_session)
    if message_json:
        if fields['schedulePost']:
            return NEW_POST_SUCCESS
        print('DEBUG: new reminder to ' +
              str(message_json['object']['to']) + ' ' +
              str(edited_postid))
        if edited_postid:
            update_edited_post(base_dir, nickname, domain,
                               message_json,
                               edited_published,
                               edited_postid,
                               recent_posts_cache,
                               'dm',
                               max_mentions,
                               max_emoji,
                               allow_local_network_access,
                               debug,
                               system_language,
                               http_prefix,
                               domain_full,
                               person_cache,
                               signing_priv_key_pem,
                               max_recent_posts,
                               translate,
                               curr_session,
                               cached_webfingers,
                               port,
                               allow_deletion,
                               yt_replace_domain,
                               twitter_replacement_domain,
                               show_published_date_only,
                               peertube_instances,
                               theme_name,
                               max_like_count,
                               cw_lists,
                               dogwhistles,
                               min_images_for_accounts,
                               max_hashtags,
                               buy_sites,
                               auto_cw_cache,
                               onion_domain, i2p_domain,
                               mitm_servers,
                               instance_software)
            print('DEBUG: sending edited reminder post ' +
                  str(message_json))
        if post_to_outbox(self, message_json,
                          project_version,
                          nickname,
                          curr_session, proxy_type):
            return NEW_POST_SUCCESS
    return NEW_POST_FAILED


def _receive_new_post_process_newreport(self, fields: {},
                                        attachment_media_type: str,
                                        city: str, base_dir: str,
                                        nickname: str, domain: str,
                                        domain_full: str,
                                        http_prefix: str,
                                        person_cache: {},
                                        content_license_url: str,
                                        port: int, mentions_str: str,
                                        filename: str,
                                        low_bandwidth: bool,
                                        debug: bool,
                                        translate: {}, auto_cw_cache: {},
                                        project_version: str,
                                        curr_session, proxy_type: str) -> int:
    """Report post has been received from New Post screen
    and is then sent to the outbox
    """
    if attachment_media_type:
        if attachment_media_type != 'image':
            return NEW_POST_FAILED
    # So as to be sure that this only goes to moderators
    # and not accounts being reported we disable any
    # included fediverse addresses by replacing '@' with '-at-'
    fields['message'] = fields['message'].replace('@', '-at-')
    city = get_spoofed_city(city, base_dir, nickname, domain)
    languages_understood = \
        get_understood_languages(base_dir, http_prefix,
                                 nickname, domain_full,
                                 person_cache)
    media_license_url = content_license_url
    if fields.get('mediaLicense'):
        media_license_url = fields['mediaLicense']
        if '://' not in media_license_url:
            media_license_url = \
                license_link_from_name(media_license_url)
    media_creator = ''
    if fields.get('mediaCreator'):
        media_creator = fields['mediaCreator']
    video_transcript = ''
    if fields.get('videoTranscript'):
        video_transcript = fields['videoTranscript']
    message_json = \
        create_report_post(base_dir, nickname, domain, port,
                           http_prefix,
                           mentions_str + fields['message'],
                           False, False, True,
                           filename, attachment_media_type,
                           fields['imageDescription'],
                           video_transcript,
                           city, debug, fields['subject'],
                           fields['languagesDropdown'],
                           low_bandwidth,
                           content_license_url,
                           media_license_url, media_creator,
                           languages_understood,
                           translate, auto_cw_cache,
                           curr_session)
    if message_json:
        if post_to_outbox(self, message_json,
                          project_version,
                          nickname,
                          curr_session, proxy_type):
            return NEW_POST_SUCCESS
    return NEW_POST_FAILED


def _receive_new_post_process_newquestion(self, fields: {},
                                          city: str, base_dir: str,
                                          nickname: str,
                                          domain: str, domain_full: str,
                                          http_prefix: str,
                                          person_cache: {},
                                          content_license_url: str,
                                          port: int,
                                          comments_enabled: bool,
                                          filename: str,
                                          attachment_media_type: str,
                                          low_bandwidth: bool,
                                          translate: {},
                                          auto_cw_cache: {},
                                          debug: bool,
                                          project_version: str,
                                          curr_session,
                                          proxy_type: str) -> int:
    """Question/poll post has been received from New Post screen
    and is then sent to the outbox
    https://codeberg.org/fediverse/fep/src/branch/main/fep/9967/fep-9967.md
    """
    # get the duration of the poll
    if not fields.get('duration'):
        if not fields.get('endTime'):
            return NEW_POST_FAILED
    if fields.get('duration'):
        duration_days = fields['duration']
    else:
        end_time_str = fields['endTime']
        date_formats = (
            "%Y-%m-%dT%H:%M:%S%z",
            "%Y-%m-%dT%H:%M:%S%Z"
        )
        poll_end_time = None
        try:
            poll_end_time = \
                date_from_string_format(end_time_str, date_formats)
        except BaseException:
            return NEW_POST_FAILED
        curr_time = date_utcnow()
        duration_days = (poll_end_time - curr_time).total_days()
        # there should be a positive duration
        if duration_days < 0:
            return NEW_POST_FAILED

    if not fields.get('message'):
        return NEW_POST_FAILED
    q_options: list[str] = []
    for question_ctr in range(8):
        if fields.get('questionOption' + str(question_ctr)):
            q_options.append(fields['questionOption' +
                                    str(question_ctr)])
    if not q_options:
        return NEW_POST_FAILED
    city = get_spoofed_city(city, base_dir, nickname, domain)
    if isinstance(duration_days, str):
        if len(duration_days) > 5:
            return NEW_POST_FAILED
    int_duration_days = int(duration_days)
    languages_understood = \
        get_understood_languages(base_dir, http_prefix,
                                 nickname, domain_full,
                                 person_cache)
    media_license_url = content_license_url
    if fields.get('mediaLicense'):
        media_license_url = fields['mediaLicense']
        if '://' not in media_license_url:
            media_license_url = \
                license_link_from_name(media_license_url)
    media_creator = ''
    if fields.get('mediaCreator'):
        media_creator = fields['mediaCreator']
    video_transcript = ''
    if fields.get('videoTranscript'):
        video_transcript = fields['videoTranscript']
    message_json = \
        create_question_post(base_dir, nickname, domain,
                             port, http_prefix,
                             fields['message'], q_options,
                             False, False,
                             comments_enabled,
                             filename, attachment_media_type,
                             fields['imageDescription'],
                             video_transcript,
                             city,
                             fields['subject'],
                             int_duration_days,
                             fields['languagesDropdown'],
                             low_bandwidth,
                             content_license_url,
                             media_license_url, media_creator,
                             languages_understood,
                             translate,
                             auto_cw_cache, curr_session)
    if message_json:
        if debug:
            print('DEBUG: new Question')
        if post_to_outbox(self, message_json,
                          project_version,
                          nickname,
                          curr_session, proxy_type):
            return NEW_POST_SUCCESS
    return NEW_POST_FAILED


def _receive_new_post_process_newreading(self, fields: {},
                                         post_type: str,
                                         content_license_url: str,
                                         base_dir: str,
                                         http_prefix: str,
                                         nickname: str, domain_full: str,
                                         person_cache: {}, city: str,
                                         domain: str, port: int,
                                         mentions_str: str,
                                         comments_enabled: bool,
                                         filename: str,
                                         attachment_media_type: str,
                                         low_bandwidth: bool,
                                         translate: {}, buy_url: str,
                                         chat_url: str,
                                         auto_cw_cache: {},
                                         edited_published: str,
                                         edited_postid: str,
                                         recent_posts_cache: {},
                                         max_mentions: int,
                                         max_emoji: int,
                                         allow_local_network_access: bool,
                                         debug: bool,
                                         system_language: str,
                                         signing_priv_key_pem: str,
                                         max_recent_posts: int,
                                         curr_session,
                                         cached_webfingers: {},
                                         allow_deletion: bool,
                                         yt_replace_domain: str,
                                         twitter_replacement_domain: str,
                                         show_published_date_only: bool,
                                         peertube_instances: [],
                                         theme_name: str,
                                         max_like_count: int,
                                         cw_lists: {},
                                         dogwhistles: {},
                                         min_images_for_accounts: {},
                                         max_hashtags: int,
                                         buy_sites: [],
                                         project_version: str,
                                         proxy_type: str,
                                         max_replies: int,
                                         onion_domain: str,
                                         i2p_domain: str,
                                         mitm_servers: [],
                                         instance_software: {}) -> int:
    """Reading status post has been received from New Post screen
    and is then sent to the outbox
    """
    if not fields.get('readingupdatetype'):
        print(post_type + ' no readingupdatetype')
        return NEW_POST_FAILED
    if fields['readingupdatetype'] not in ('readingupdatewant',
                                           'readingupdateread',
                                           'readingupdatefinished',
                                           'readingupdaterating'):
        print(post_type + ' not recognised ' +
              fields['readingupdatetype'])
        return NEW_POST_FAILED
    if not fields.get('booktitle'):
        print(post_type + ' no booktitle')
        return NEW_POST_FAILED
    if not fields.get('bookurl'):
        print(post_type + ' no bookurl')
        return NEW_POST_FAILED
    book_rating = 0.0
    if fields.get('bookrating'):
        if isinstance(fields['bookrating'], (float, int)):
            book_rating = fields['bookrating']
    media_license_url = content_license_url
    if fields.get('mediaLicense'):
        media_license_url = fields['mediaLicense']
        if '://' not in media_license_url:
            media_license_url = \
                license_link_from_name(media_license_url)
    media_creator = ''
    if fields.get('mediaCreator'):
        media_creator = fields['mediaCreator']
    video_transcript = ''
    if fields.get('videoTranscript'):
        video_transcript = fields['videoTranscript']
    conversation_id = None
    convthread_id = None
    languages_understood = \
        get_understood_languages(base_dir, http_prefix,
                                 nickname, domain_full,
                                 person_cache)
    city = get_spoofed_city(city, base_dir,
                            nickname, domain)
    msg_str = fields['readingupdatetype']
    if fields.get('searchableByDropdown'):
        set_searchable_by(base_dir, nickname, domain,
                          fields['searchableByDropdown'])
    # reading status
    message_json = \
        create_reading_post(base_dir, nickname, domain,
                            port, http_prefix,
                            mentions_str, msg_str,
                            fields['booktitle'],
                            fields['bookurl'],
                            book_rating,
                            False, False, comments_enabled,
                            filename, attachment_media_type,
                            fields['imageDescription'],
                            video_transcript,
                            city, None, None,
                            fields['subject'],
                            fields['schedulePost'],
                            fields['eventDate'],
                            fields['eventTime'],
                            fields['eventEndTime'],
                            fields['location'], False,
                            fields['languagesDropdown'],
                            conversation_id, convthread_id,
                            low_bandwidth,
                            content_license_url,
                            media_license_url, media_creator,
                            languages_understood,
                            translate, buy_url,
                            chat_url,
                            auto_cw_cache,
                            fields['searchableByDropdown'],
                            curr_session)
    if message_json:
        if edited_postid:
            update_edited_post(base_dir, nickname, domain,
                               message_json,
                               edited_published,
                               edited_postid,
                               recent_posts_cache,
                               'outbox',
                               max_mentions,
                               max_emoji,
                               allow_local_network_access,
                               debug,
                               system_language,
                               http_prefix,
                               domain_full,
                               person_cache,
                               signing_priv_key_pem,
                               max_recent_posts,
                               translate,
                               curr_session,
                               cached_webfingers,
                               port,
                               allow_deletion,
                               yt_replace_domain,
                               twitter_replacement_domain,
                               show_published_date_only,
                               peertube_instances,
                               theme_name,
                               max_like_count,
                               cw_lists,
                               dogwhistles,
                               min_images_for_accounts,
                               max_hashtags,
                               buy_sites,
                               auto_cw_cache,
                               onion_domain, i2p_domain,
                               mitm_servers,
                               instance_software)
            print('DEBUG: sending edited reading status post ' +
                  str(message_json))
        if fields['schedulePost']:
            return NEW_POST_SUCCESS
        if not fields.get('pinToProfile'):
            pin_to_profile = False
        else:
            pin_to_profile = True
        if pin_to_profile:
            sys_language = system_language
            content_str = \
                get_base_content_from_post(message_json,
                                           sys_language)
            pin_post2(base_dir, nickname, domain, content_str)
            return NEW_POST_SUCCESS
        if post_to_outbox(self, message_json,
                          project_version,
                          nickname,
                          curr_session, proxy_type):
            populate_replies(base_dir, http_prefix,
                             domain_full,
                             message_json,
                             max_replies,
                             debug)
            return NEW_POST_SUCCESS
    return NEW_POST_FAILED


def _receive_new_post_process_newshare(self, fields: {},
                                       post_type: str,
                                       attachment_media_type: str,
                                       city: str, base_dir: str,
                                       nickname: str, domain: str,
                                       http_prefix: str, port: int,
                                       filename: str, debug: bool,
                                       translate: {},
                                       low_bandwidth: bool,
                                       content_license_url: str,
                                       block_federated: bool,
                                       calling_domain: str,
                                       domain_full: str,
                                       onion_domain: str,
                                       i2p_domain: str,
                                       person_cache: {},
                                       max_shares_on_profile: int,
                                       project_version: str,
                                       curr_session,
                                       proxy_type: str) -> int:
    """Shared/Wanted item post has been received from New Post screen
    and is then sent to the outbox
    """
    if not fields.get('itemQty'):
        print(post_type + ' no itemQty')
        return NEW_POST_FAILED
    if not fields.get('itemType'):
        print(post_type + ' no itemType')
        return NEW_POST_FAILED
    if 'itemPrice' not in fields:
        print(post_type + ' no itemPrice')
        return NEW_POST_FAILED
    if 'itemCurrency' not in fields:
        print(post_type + ' no itemCurrency')
        return NEW_POST_FAILED
    if not fields.get('category'):
        print(post_type + ' no category')
        return NEW_POST_FAILED
    if not fields.get('duration'):
        print(post_type + ' no duratio')
        return NEW_POST_FAILED
    if attachment_media_type:
        if attachment_media_type != 'image':
            print('Attached media is not an image')
            return NEW_POST_FAILED
    duration_str = fields['duration']
    if duration_str:
        if ' ' not in duration_str:
            duration_str = duration_str + ' days'
    city = get_spoofed_city(city, base_dir, nickname, domain)
    item_qty = 1
    if fields['itemQty']:
        if is_float(fields['itemQty']):
            item_qty = float(fields['itemQty'])
    item_price = "0.00"
    item_currency = "EUR"
    if fields['itemPrice']:
        item_price, item_currency = \
            get_price_from_string(fields['itemPrice'])
    if fields['itemCurrency']:
        item_currency = fields['itemCurrency']
    if post_type == 'newshare':
        print('Adding shared item')
        shares_file_type = 'shares'
    else:
        print('Adding wanted item')
        shares_file_type = 'wanted'
    share_on_profile = False
    if fields.get('shareOnProfile'):
        if fields['shareOnProfile'] == 'on':
            share_on_profile = True
    add_share(base_dir, http_prefix, nickname, domain, port,
              fields['subject'],
              fields['message'],
              filename,
              item_qty, fields['itemType'],
              fields['category'],
              fields['location'],
              duration_str,
              debug,
              city, item_price, item_currency,
              fields['languagesDropdown'],
              translate, shares_file_type,
              low_bandwidth,
              content_license_url,
              share_on_profile,
              block_federated)
    if post_type == 'newshare':
        # add shareOnProfile items to the actor attachments
        # https://codeberg.org/fediverse/fep/src/branch/main/fep/0837/fep-0837.md
        actor = \
            get_instance_url(calling_domain,
                             http_prefix, domain_full,
                             onion_domain,
                             i2p_domain) + \
            '/users/' + nickname
        actor_json = get_person_from_cache(base_dir,
                                           actor, person_cache)
        if not actor_json:
            actor_filename = \
                acct_dir(base_dir, nickname, domain) + '.json'
            if os.path.isfile(actor_filename):
                actor_json = load_json(actor_filename)
        if actor_json:
            if add_shares_to_actor(base_dir, nickname, domain,
                                   actor_json,
                                   max_shares_on_profile):
                remove_person_from_cache(base_dir,
                                         actor, person_cache)
                store_person_in_cache(base_dir, actor,
                                      actor_json, person_cache,
                                      True)
                actor_filename = \
                    acct_dir(base_dir, nickname, domain) + '.json'
                save_json(actor_json, actor_filename)
                # send profile update to followers
                update_actor_json = \
                    get_actor_update_json(actor_json)
                print('Sending actor update ' +
                      'after change to attached shares: ' +
                      str(update_actor_json))
                post_to_outbox(self, update_actor_json,
                               project_version,
                               nickname,
                               curr_session, proxy_type)

    if filename:
        if os.path.isfile(filename):
            try:
                os.remove(filename)
            except OSError:
                print('EX: _receive_new_post_process ' +
                      'unable to delete ' + filename)
    self.post_to_nickname = nickname
    return NEW_POST_SUCCESS


def _receive_new_post_process(self, post_type: str, path: str, headers: {},
                              length: int, post_bytes, boundary: str,
                              calling_domain: str, cookie: str,
                              content_license_url: str,
                              curr_session, proxy_type: str,
                              base_dir: str, debug: bool,
                              max_post_length: int,
                              domain: str, city: str,
                              low_bandwidth: bool, translate: {},
                              system_language: str,
                              http_prefix: str,
                              domain_full: str,
                              person_cache: {},
                              port: int, auto_cw_cache: {},
                              recent_posts_cache: {},
                              allow_local_network_access: bool,
                              yt_replace_domain: str,
                              twitter_replacement_domain: str,
                              signing_priv_key_pem: str,
                              show_published_date_only: bool,
                              min_images_for_accounts: [],
                              peertube_instances: [],
                              max_mentions: int,
                              max_emoji: int, max_recent_posts: int,
                              cached_webfingers: {},
                              allow_deletion: bool,
                              theme_name: str,
                              max_like_count: int,
                              cw_lists: {},
                              dogwhistles: {},
                              max_hashtags: int,
                              buy_sites: [],
                              project_version: str,
                              max_replies: int,
                              newswire: {},
                              dm_license_url: str,
                              block_federated: [],
                              onion_domain: str,
                              i2p_domain: str,
                              max_shares_on_profile: int,
                              watermark_width_percent: int,
                              watermark_position: str,
                              watermark_opacity: int,
                              mitm_servers: [],
                              instance_software: {}) -> int:
    # Note: this needs to happen synchronously
    # 0=this is not a new post
    # 1=new post success
    # -1=new post failed
    # 2=new post cancelled
    if debug:
        print('DEBUG: receiving POST')

    if ' boundary=' not in headers['Content-Type']:
        return NEW_POST_FAILED

    if debug:
        print('DEBUG: receiving POST headers ' +
              headers['Content-Type'] +
              ' path ' + path)
    nickname = None
    nickname_str = path.split('/users/')[1]
    if '?' in nickname_str:
        nickname_str = nickname_str.split('?')[0]
    if '/' in nickname_str:
        nickname = nickname_str.split('/')[0]
    else:
        nickname = nickname_str
    if debug:
        print('DEBUG: POST nickname ' + str(nickname))
    if not nickname:
        print('WARN: no nickname found when receiving ' + post_type +
              ' path ' + path)
        return NEW_POST_FAILED

    # get the message id of an edited post
    edited_postid = None
    print('DEBUG: edited_postid path ' + path)
    if '?editid=' in path:
        edited_postid = path.split('?editid=')[1]
        if '?' in edited_postid:
            edited_postid = edited_postid.split('?')[0]
        print('DEBUG: edited_postid ' + edited_postid)

    # get the published date of an edited post
    edited_published = None
    if '?editpub=' in path:
        edited_published = path.split('?editpub=')[1]
        if '?' in edited_published:
            edited_published = \
                edited_published.split('?')[0]
        print('DEBUG: edited_published ' +
              edited_published)

    length = int(headers['Content-Length'])
    if length > max_post_length:
        print('POST size too large')
        return NEW_POST_FAILED

    boundary = headers['Content-Type'].split('boundary=')[1]
    if ';' in boundary:
        boundary = boundary.split(';')[0]

    # Note: we don't use cgi here because it's due to be deprecated
    # in Python 3.8/3.10
    # Instead we use the multipart mime parser from the email module
    if debug:
        print('DEBUG: extracting media from POST')
    media_bytes, post_bytes = \
        extract_media_in_form_post(post_bytes, boundary, 'attachpic')
    if debug:
        if media_bytes:
            print('DEBUG: media was found. ' +
                  str(len(media_bytes)) + ' bytes')
        else:
            print('DEBUG: no media was found in POST')

    # Note: a .temp extension is used here so that at no time is
    # an image with metadata publicly exposed, even for a few mS
    filename_base = \
        acct_dir(base_dir, nickname, domain) + '/upload.temp'

    filename, attachment_media_type = \
        save_media_in_form_post(media_bytes, debug, filename_base)
    if debug:
        if filename:
            print('DEBUG: POST media filename is ' + filename)
        else:
            print('DEBUG: no media filename in POST')

    if filename:
        if is_image_file(filename):
            # convert to low bandwidth if needed
            if low_bandwidth:
                print('Converting to low bandwidth ' + filename)
                convert_image_to_low_bandwidth(filename)
            apply_watermark_to_image(base_dir, nickname, domain,
                                     filename, watermark_width_percent,
                                     watermark_position,
                                     watermark_opacity)
            post_image_filename = filename.replace('.temp', '')
            print('Removing metadata from ' + post_image_filename)
            city = get_spoofed_city(city, base_dir, nickname, domain)
            process_meta_data(base_dir, nickname, domain,
                              filename, post_image_filename, city,
                              content_license_url)
            if os.path.isfile(post_image_filename):
                print('POST media saved to ' + post_image_filename)
            else:
                print('ERROR: POST media could not be saved to ' +
                      post_image_filename)
        else:
            if os.path.isfile(filename):
                new_filename = filename.replace('.temp', '')
                os.rename(filename, new_filename)
                filename = new_filename

    fields = \
        extract_text_fields_in_post(post_bytes, boundary, debug, None)
    if debug:
        if fields:
            print('DEBUG: text field extracted from POST ' +
                  str(fields))
        else:
            print('WARN: no text fields could be extracted from POST')

    # was the citations button pressed on the newblog screen?
    citations_button_press = False
    if post_type == 'newblog' and fields.get('submitCitations'):
        if fields['submitCitations'] == translate['Citations']:
            citations_button_press = True

    if not citations_button_press:
        # process the received text fields from the POST
        if not fields.get('message') and \
           not fields.get('imageDescription') and \
           not fields.get('pinToProfile'):
            print('WARN: no message, image description or pin')
            return NEW_POST_FAILED
        submit_text1 = translate['Publish']
        submit_text2 = translate['Send']
        submit_text3 = submit_text2
        custom_submit_text = \
            get_config_param(base_dir, 'customSubmitText')
        if custom_submit_text:
            submit_text3 = custom_submit_text
        if fields.get('submitPost'):
            if fields['submitPost'] != submit_text1 and \
               fields['submitPost'] != submit_text2 and \
               fields['submitPost'] != submit_text3:
                print('WARN: no submit field ' + fields['submitPost'])
                return NEW_POST_FAILED
        else:
            print('WARN: no submitPost')
            return NEW_POST_CANCELLED

    if not fields.get('imageDescription'):
        fields['imageDescription'] = None
    if not fields.get('videoTranscript'):
        fields['videoTranscript'] = None
    if not fields.get('subject'):
        fields['subject'] = None
    if not fields.get('replyTo'):
        fields['replyTo'] = None

    if not fields.get('schedulePost'):
        fields['schedulePost'] = False
    else:
        fields['schedulePost'] = True
    print('DEBUG: shedulePost ' + str(fields['schedulePost']))

    if not fields.get('eventDate'):
        fields['eventDate'] = None
    if not fields.get('eventTime'):
        fields['eventTime'] = None
    if not fields.get('eventEndTime'):
        fields['eventEndTime'] = None
    if not fields.get('location'):
        fields['location'] = None
    if not fields.get('languagesDropdown'):
        fields['languagesDropdown'] = system_language
    set_default_post_language(base_dir, nickname, domain,
                              fields['languagesDropdown'])
    self.server.default_post_language[nickname] = \
        fields['languagesDropdown']
    if 'searchableByDropdown' not in fields:
        fields['searchableByDropdown']: list[str] = []

    if not citations_button_press:
        # Store a file which contains the time in seconds
        # since epoch when an attempt to post something was made.
        # This is then used for active monthly users counts
        last_used_filename = \
            acct_dir(base_dir, nickname, domain) + '/.lastUsed'
        try:
            with open(last_used_filename, 'w+',
                      encoding='utf-8') as fp_last:
                fp_last.write(str(int(time.time())))
        except OSError:
            print('EX: _receive_new_post_process unable to write ' +
                  last_used_filename)

    mentions_str = ''
    if fields.get('mentions'):
        mentions_str = fields['mentions'].strip() + ' '
    if not fields.get('commentsEnabled'):
        comments_enabled = False
    else:
        comments_enabled = True

    buy_url = ''
    if fields.get('buyUrl'):
        buy_url = fields['buyUrl']

    chat_url = ''
    if fields.get('chatUrl'):
        chat_url = fields['chatUrl']

    if post_type == 'newpost':
        return _receive_new_post_process_newpost(
            self, fields,
            base_dir, nickname,
            domain, domain_full, port,
            city, http_prefix,
            person_cache,
            content_license_url,
            mentions_str,
            comments_enabled,
            filename,
            attachment_media_type,
            low_bandwidth,
            translate,
            buy_url,
            chat_url,
            auto_cw_cache,
            edited_postid,
            edited_published,
            recent_posts_cache,
            max_mentions,
            max_emoji,
            allow_local_network_access,
            debug,
            system_language,
            signing_priv_key_pem,
            max_recent_posts,
            curr_session,
            cached_webfingers,
            allow_deletion,
            yt_replace_domain,
            twitter_replacement_domain,
            show_published_date_only,
            peertube_instances,
            theme_name,
            max_like_count,
            cw_lists,
            dogwhistles,
            min_images_for_accounts,
            max_hashtags,
            buy_sites,
            project_version,
            proxy_type,
            max_replies,
            onion_domain,
            i2p_domain,
            mitm_servers,
            instance_software)
    if post_type == 'newblog':
        return _receive_new_post_process_newblog(
            self, fields,
            citations_button_press,
            base_dir, nickname,
            newswire, theme_name,
            domain, domain_full,
            port, translate,
            cookie, calling_domain,
            http_prefix, person_cache,
            content_license_url,
            comments_enabled, filename,
            attachment_media_type,
            low_bandwidth,
            buy_url, chat_url,
            project_version, curr_session,
            proxy_type, max_replies, debug)
    if post_type == 'editblogpost':
        return _receive_new_post_process_editblog(
            self, fields, base_dir, nickname, domain,
            recent_posts_cache,
            http_prefix, translate,
            curr_session, debug,
            system_language,
            port, filename,
            city, content_license_url,
            attachment_media_type,
            low_bandwidth,
            yt_replace_domain,
            twitter_replacement_domain)
    if post_type == 'newunlisted':
        return _receive_new_post_process_newunlisted(
            self, fields,
            city, base_dir,
            nickname, domain,
            domain_full,
            http_prefix,
            person_cache,
            content_license_url,
            port, mentions_str,
            comments_enabled,
            filename,
            attachment_media_type,
            low_bandwidth,
            translate, buy_url,
            chat_url,
            auto_cw_cache,
            edited_postid,
            edited_published,
            recent_posts_cache,
            max_mentions,
            max_emoji,
            allow_local_network_access,
            debug,
            system_language,
            signing_priv_key_pem,
            max_recent_posts,
            curr_session,
            cached_webfingers,
            allow_deletion,
            yt_replace_domain,
            twitter_replacement_domain,
            show_published_date_only,
            peertube_instances,
            theme_name,
            max_like_count,
            cw_lists,
            dogwhistles,
            min_images_for_accounts,
            max_hashtags,
            buy_sites,
            project_version,
            proxy_type,
            max_replies,
            onion_domain,
            i2p_domain,
            mitm_servers,
            instance_software)
    if post_type == 'newfollowers':
        return _receive_new_post_process_newfollowers(
            self, fields,
            city, base_dir,
            nickname, domain,
            domain_full,
            mentions_str,
            http_prefix,
            person_cache,
            content_license_url,
            port, comments_enabled,
            filename,
            attachment_media_type,
            low_bandwidth,
            translate,
            buy_url, chat_url,
            auto_cw_cache,
            edited_postid,
            edited_published,
            recent_posts_cache,
            max_mentions,
            max_emoji,
            allow_local_network_access,
            debug,
            system_language,
            signing_priv_key_pem,
            max_recent_posts,
            curr_session,
            cached_webfingers,
            allow_deletion,
            yt_replace_domain,
            twitter_replacement_domain,
            show_published_date_only,
            peertube_instances,
            theme_name,
            max_like_count,
            cw_lists,
            dogwhistles,
            min_images_for_accounts,
            max_hashtags,
            buy_sites,
            project_version,
            proxy_type,
            max_replies,
            onion_domain, i2p_domain,
            mitm_servers,
            instance_software)
    if post_type == 'newdm':
        return _receive_new_post_process_newdm(
            self, fields,
            mentions_str,
            city, base_dir,
            nickname, domain,
            domain_full,
            http_prefix,
            person_cache,
            content_license_url,
            port, comments_enabled,
            filename,
            attachment_media_type,
            low_bandwidth,
            dm_license_url,
            translate,
            buy_url, chat_url,
            auto_cw_cache,
            edited_postid,
            edited_published,
            recent_posts_cache,
            max_mentions,
            max_emoji,
            allow_local_network_access,
            debug,
            system_language,
            signing_priv_key_pem,
            max_recent_posts,
            curr_session,
            cached_webfingers,
            allow_deletion,
            yt_replace_domain,
            twitter_replacement_domain,
            show_published_date_only,
            peertube_instances,
            theme_name,
            max_like_count,
            cw_lists,
            dogwhistles,
            min_images_for_accounts,
            max_hashtags,
            buy_sites,
            project_version,
            proxy_type,
            max_replies,
            onion_domain,
            i2p_domain,
            mitm_servers,
            instance_software)
    if post_type == 'newreminder':
        return _receive_new_post_process_newreminder(
            self, fields,
            nickname,
            domain, domain_full,
            mentions_str,
            city, base_dir,
            http_prefix,
            person_cache,
            port,
            filename,
            attachment_media_type,
            low_bandwidth,
            dm_license_url,
            translate,
            buy_url, chat_url,
            auto_cw_cache,
            edited_postid,
            edited_published,
            recent_posts_cache,
            max_mentions,
            max_emoji,
            allow_local_network_access,
            debug,
            system_language,
            signing_priv_key_pem,
            max_recent_posts,
            curr_session,
            cached_webfingers,
            allow_deletion,
            yt_replace_domain,
            twitter_replacement_domain,
            show_published_date_only,
            peertube_instances,
            theme_name,
            max_like_count,
            cw_lists,
            dogwhistles,
            min_images_for_accounts,
            max_hashtags,
            buy_sites,
            project_version,
            proxy_type,
            onion_domain, i2p_domain,
            mitm_servers,
            instance_software)
    if post_type == 'newreport':
        return _receive_new_post_process_newreport(
            self, fields,
            attachment_media_type,
            city, base_dir,
            nickname, domain,
            domain_full,
            http_prefix,
            person_cache,
            content_license_url,
            port, mentions_str,
            filename,
            low_bandwidth,
            debug,
            translate, auto_cw_cache,
            project_version,
            curr_session, proxy_type)
    if post_type == 'newquestion':
        return _receive_new_post_process_newquestion(
            self, fields, city, base_dir, nickname,
            domain, domain_full, http_prefix,
            person_cache, content_license_url,
            port, comments_enabled, filename,
            attachment_media_type,
            low_bandwidth, translate, auto_cw_cache,
            debug, project_version, curr_session,
            proxy_type)
    if post_type == 'newreadingstatus':
        return _receive_new_post_process_newreading(
            self, fields,
            post_type,
            content_license_url,
            base_dir,
            http_prefix,
            nickname, domain_full,
            person_cache, city,
            domain, port,
            mentions_str,
            comments_enabled,
            filename,
            attachment_media_type,
            low_bandwidth,
            translate, buy_url,
            chat_url,
            auto_cw_cache,
            edited_published,
            edited_postid,
            recent_posts_cache,
            max_mentions,
            max_emoji,
            allow_local_network_access,
            debug,
            system_language,
            signing_priv_key_pem,
            max_recent_posts,
            curr_session,
            cached_webfingers,
            allow_deletion,
            yt_replace_domain,
            twitter_replacement_domain,
            show_published_date_only,
            peertube_instances,
            theme_name,
            max_like_count,
            cw_lists,
            dogwhistles,
            min_images_for_accounts,
            max_hashtags,
            buy_sites,
            project_version,
            proxy_type,
            max_replies,
            onion_domain, i2p_domain,
            mitm_servers,
            instance_software)
    if post_type in ('newshare', 'newwanted'):
        return _receive_new_post_process_newshare(
            self, fields,
            post_type,
            attachment_media_type,
            city, base_dir,
            nickname, domain,
            http_prefix, port,
            filename, debug,
            translate,
            low_bandwidth,
            content_license_url,
            block_federated,
            calling_domain,
            domain_full,
            onion_domain,
            i2p_domain,
            person_cache,
            max_shares_on_profile,
            project_version,
            curr_session,
            proxy_type)
    return NEW_POST_FAILED


def receive_new_post(self, post_type, path: str,
                     calling_domain: str, cookie: str,
                     content_license_url: str,
                     curr_session, proxy_type: str,
                     base_dir: str, debug: bool,
                     max_post_length: int, domain: str,
                     city: str, low_bandwidth: bool, translate: {},
                     system_language: str, http_prefix: str,
                     domain_full: str, person_cache: {},
                     port: int, auto_cw_cache: {},
                     recent_posts_cache: {},
                     allow_local_network_access: bool,
                     yt_replace_domain: str,
                     twitter_replacement_domain: str,
                     signing_priv_key_pem: str,
                     show_published_date_only: bool,
                     min_images_for_accounts: [],
                     peertube_instances: [],
                     max_mentions: int, max_emoji: int,
                     max_recent_posts: int,
                     cached_webfingers: {},
                     allow_deletion: bool,
                     theme_name: str,
                     max_like_count: int,
                     cw_lists: {},
                     dogwhistles: {},
                     max_hashtags: int,
                     buy_sites: [], project_version: str,
                     max_replies: int, newswire: {},
                     dm_license_url: str,
                     block_federated: [],
                     onion_domain: str,
                     i2p_domain: str,
                     max_shares_on_profile: int,
                     watermark_width_percent: int,
                     watermark_position: str,
                     watermark_opacity: int,
                     mitm_servers: [],
                     instance_software: {}) -> int:
    """A new post has been created
    This creates a thread to send the new post
    """
    page_number = 1
    original_path = path

    if '/users/' not in path:
        print('Not receiving new post for ' + path +
              ' because /users/ not in path')
        return None

    if '?' + post_type + '?' not in path:
        print('Not receiving new post for ' + path +
              ' because ?' + post_type + '? not in path')
        return None

    print('New post begins: ' + post_type + ' ' + path)

    if '?page=' in path:
        page_number_str = path.split('?page=')[1]
        if '?' in page_number_str:
            page_number_str = page_number_str.split('?')[0]
        if '#' in page_number_str:
            page_number_str = page_number_str.split('#')[0]
        if len(page_number_str) > 5:
            page_number_str = "1"
        if page_number_str.isdigit():
            page_number = int(page_number_str)
            path = path.split('?page=')[0]

    # get the username who posted
    new_post_thread_name = None
    if '/users/' in path:
        new_post_thread_name = path.split('/users/')[1]
        if '/' in new_post_thread_name:
            new_post_thread_name = new_post_thread_name.split('/')[0]
    if not new_post_thread_name:
        new_post_thread_name = '*'

    if self.server.new_post_thread.get(new_post_thread_name):
        print('Waiting for previous new post thread to end')
        wait_ctr = 0
        np_thread = self.server.new_post_thread[new_post_thread_name]
        while np_thread.is_alive() and wait_ctr < 8:
            time.sleep(1)
            wait_ctr += 1
        if wait_ctr >= 8:
            print('Killing previous new post thread for ' +
                  new_post_thread_name)
            np_thread.kill()

    # make a copy of self.headers
    headers = copy.deepcopy(self.headers)
    headers_without_cookie = copy.deepcopy(headers)
    if 'cookie' in headers_without_cookie:
        del headers_without_cookie['cookie']
    if 'Cookie' in headers_without_cookie:
        del headers_without_cookie['Cookie']
    print('New post headers: ' + str(headers_without_cookie))

    length = int(headers['Content-Length'])
    if length > max_post_length:
        print('POST size too large')
        return None

    if not headers.get('Content-Type'):
        if headers.get('Content-type'):
            headers['Content-Type'] = headers['Content-type']
        elif headers.get('content-type'):
            headers['Content-Type'] = headers['content-type']
    if headers.get('Content-Type'):
        if ' boundary=' in headers['Content-Type']:
            boundary = headers['Content-Type'].split('boundary=')[1]
            if ';' in boundary:
                boundary = boundary.split(';')[0]

            try:
                post_bytes = self.rfile.read(length)
            except SocketError as ex:
                if ex.errno == errno.ECONNRESET:
                    print('WARN: POST post_bytes ' +
                          'connection reset by peer')
                else:
                    print('WARN: POST post_bytes socket error')
                return None
            except ValueError as ex:
                print('EX: POST post_bytes rfile.read failed, ' +
                      str(ex))
                return None

            # second length check from the bytes received
            # since Content-Length could be untruthful
            length = len(post_bytes)
            if length > max_post_length:
                print('POST size too large')
                return None

            # Note sending new posts needs to be synchronous,
            # otherwise any attachments can get mangled if
            # other events happen during their decoding
            print('Creating new post from: ' + new_post_thread_name)
            retval = \
                _receive_new_post_process(self, post_type,
                                          original_path,
                                          headers, length,
                                          post_bytes, boundary,
                                          calling_domain, cookie,
                                          content_license_url,
                                          curr_session, proxy_type,
                                          base_dir, debug, max_post_length,
                                          domain, city, low_bandwidth,
                                          translate, system_language,
                                          http_prefix, domain_full,
                                          person_cache,
                                          port, auto_cw_cache,
                                          recent_posts_cache,
                                          allow_local_network_access,
                                          yt_replace_domain,
                                          twitter_replacement_domain,
                                          signing_priv_key_pem,
                                          show_published_date_only,
                                          min_images_for_accounts,
                                          peertube_instances,
                                          max_mentions, max_emoji,
                                          max_recent_posts,
                                          cached_webfingers,
                                          allow_deletion,
                                          theme_name,
                                          max_like_count,
                                          cw_lists,
                                          dogwhistles,
                                          max_hashtags,
                                          buy_sites, project_version,
                                          max_replies, newswire,
                                          dm_license_url, block_federated,
                                          onion_domain,
                                          i2p_domain,
                                          max_shares_on_profile,
                                          watermark_width_percent,
                                          watermark_position,
                                          watermark_opacity,
                                          mitm_servers,
                                          instance_software)
            if debug:
                print('DEBUG: _receive_new_post_process returned ' +
                      str(retval))
    return page_number
