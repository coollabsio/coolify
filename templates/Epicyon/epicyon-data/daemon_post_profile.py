__filename__ = "daemon_post_profile.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon POST"

import os
import errno
from webfinger import webfinger_update
from socket import error as SocketError
from blocking import save_blocked_military
from blocking import save_blocked_government
from blocking import save_blocked_bluesky
from blocking import save_blocked_nostr
from httpheaders import redirect_headers
from httpheaders import clear_login_details
from flags import is_artist
from flags import is_memorial_account
from flags import is_premium_account
from utils import data_dir
from utils import set_premium_account
from utils import remove_avatar_from_cache
from utils import save_json
from utils import save_reverse_timeline
from utils import set_minimize_all_images
from utils import set_account_timezone
from utils import get_account_timezone
from utils import set_memorials
from utils import get_memorials
from utils import license_link_from_name
from utils import resembles_url
from utils import set_config_param
from utils import set_reply_interval_hours
from utils import valid_password
from utils import remove_eol
from utils import remove_html
from utils import get_url_from_post
from utils import load_json
from utils import acct_dir
from utils import get_config_param
from utils import get_instance_url
from utils import get_nickname_from_actor
from utils import get_occupation_name
from auth import store_basic_credentials
from filters import is_filtered
from content import add_name_emojis_to_tags
from content import add_html_tags
from content import extract_text_fields_in_post
from content import extract_media_in_form_post
from content import save_media_in_form_post
from theme import enable_grayscale
from theme import disable_grayscale
from theme import get_theme
from theme import is_news_theme_name
from theme import set_news_avatar
from theme import get_text_mode_banner
from theme import set_theme
from theme import export_theme
from theme import import_theme
from city import get_spoofed_city
from media import convert_image_to_low_bandwidth
from media import process_meta_data
from webapp_welcome import welcome_screen_is_complete
from skills import no_of_actor_skills
from skills import actor_has_skill
from skills import actor_skill_value
from skills import set_actor_skill_level
from categories import set_hashtag_category
from person import deactivate_account
from person import get_actor_move_json
from person import get_actor_update_json
from person import add_actor_update_timestamp
from person import randomize_actor_images
from person import get_default_person_context
from person import update_memorial_flags
from pgp import set_pgp_pub_key
from pgp import get_pgp_pub_key
from pgp import get_email_address
from pgp import set_email_address
from pgp import get_deltachat_invite
from pgp import set_deltachat_invite
from pgp import set_pgp_fingerprint
from pgp import get_pgp_fingerprint
from pronouns import get_pronouns
from pronouns import set_pronouns
from discord import get_discord
from discord import set_discord
from music import get_music_site_url
from music import set_music_site_url
from art import get_art_site_url
from art import set_art_site_url
from youtube import get_youtube
from youtube import set_youtube
from pixelfed import get_pixelfed
from pixelfed import set_pixelfed
from peertube import get_peertube
from peertube import set_peertube
from xmpp import get_xmpp_address
from xmpp import set_xmpp_address
from matrix import get_matrix_address
from matrix import set_matrix_address
from ssb import get_ssb_address
from ssb import set_ssb_address
from utils import set_occupation_name
from blog import get_blog_address
from webapp_utils import set_blog_address
from session import site_is_verified
from languages import set_actor_languages
from languages import get_actor_languages
from posts import is_moderator
from posts import set_post_expiry_keep_dms
from posts import get_post_expiry_keep_dms
from posts import set_post_expiry_days
from posts import get_post_expiry_days
from posts import set_max_profile_posts
from posts import get_max_profile_posts
from tox import get_tox_address
from tox import set_tox_address
from briar import get_briar_address
from briar import set_briar_address
from cwtch import get_cwtch_address
from cwtch import set_cwtch_address
from enigma import get_enigma_pub_key
from enigma import set_enigma_pub_key
from website import get_website
from website import set_website
from website import get_gemini_link
from website import set_gemini_link
from donate import get_donation_url
from donate import set_donation_url
from person import get_featured_hashtags
from person import set_featured_hashtags
from blocking import save_block_federated_endpoints
from blocking import import_blocking_file
from blocking import add_account_blocks
from blocking import set_broch_mode
from shares import merge_shared_item_tokens
from roles import set_roles_from_list
from schedule import remove_scheduled_posts
from cwlists import get_cw_list_variable
from cache import store_person_in_cache
from daemon_utils import post_to_outbox


def _profile_post_deactivate_account(base_dir: str, nickname: str, domain: str,
                                     calling_domain: str,
                                     fields: {}, self) -> bool:
    """ HTTP POST deactivate the account
    """
    deactivated = False
    if fields.get('deactivateThisAccount'):
        if fields['deactivateThisAccount'] == 'on':
            deactivate_account(base_dir, nickname, domain)
            clear_login_details(self, nickname, calling_domain)
            self.server.postreq_busy = False
            deactivated = True
    return deactivated


def _profile_post_save_actor(base_dir: str, http_prefix: str,
                             nickname: str, domain: str, port: int,
                             actor_json: {}, actor_filename: str,
                             onion_domain: str, i2p_domain: str,
                             curr_session, proxy_type: str,
                             send_move_activity: bool,
                             self, cached_webfingers: {},
                             person_cache: {}, project_version: str) -> None:
    """ HTTP POST save actor json file within accounts
    """
    add_name_emojis_to_tags(base_dir, http_prefix,
                            domain, port,
                            actor_json)
    # update the context for the actor
    actor_json['@context'] = [
        'https://www.w3.org/ns/activitystreams',
        'https://w3id.org/security/v1',
        get_default_person_context()
    ]
    if actor_json.get('nomadicLocations'):
        del actor_json['nomadicLocations']
    if not actor_json.get('featured'):
        actor_json['featured'] = actor_json['id'] + '/collections/featured'
    if not actor_json.get('featuredTags'):
        actor_json['featuredTags'] = actor_json['id'] + '/collections/tags'
    randomize_actor_images(actor_json)
    add_actor_update_timestamp(actor_json)
    # save the actor
    save_json(actor_json, actor_filename)
    webfinger_update(base_dir, nickname, domain,
                     onion_domain, i2p_domain,
                     cached_webfingers)
    # also copy to the actors cache and
    # person_cache in memory
    store_person_in_cache(base_dir, actor_json['id'], actor_json,
                          person_cache, True)
    # clear any cached images for this actor
    id_str = actor_json['id'].replace('/', '-')
    remove_avatar_from_cache(base_dir, id_str)
    # save the actor to the cache
    actor_cache_filename = \
        base_dir + '/cache/actors/' + \
        actor_json['id'].replace('/', '#') + '.json'
    save_json(actor_json, actor_cache_filename)
    # send profile update to followers
    update_actor_json = get_actor_update_json(actor_json)
    print('Sending actor update: ' + str(update_actor_json))
    post_to_outbox(self, update_actor_json,
                   project_version, nickname,
                   curr_session, proxy_type)
    # send move activity if necessary
    if send_move_activity:
        move_actor_json = get_actor_move_json(actor_json)
        print('Sending Move activity: ' + str(move_actor_json))
        post_to_outbox(self, move_actor_json,
                       project_version,
                       nickname,
                       curr_session, proxy_type)


def _profile_post_memorial(base_dir: str, nickname: str,
                           actor_json: {},
                           actor_changed: bool) -> bool:
    """ HTTP POST change memorial status
    """
    if is_memorial_account(base_dir, nickname):
        if not actor_json.get('memorial'):
            actor_json['memorial'] = True
            actor_changed = True
    elif actor_json.get('memorial'):
        actor_json['memorial'] = False
        actor_changed = True
    return actor_changed


def _profile_post_git_projects(base_dir: str, nickname: str, domain: str,
                               fields: {}) -> None:
    """ HTTP POST save git project names list
    """
    git_projects_filename = \
        acct_dir(base_dir, nickname, domain) + '/gitprojects.txt'
    if fields.get('gitProjects'):
        try:
            with open(git_projects_filename, 'w+',
                      encoding='utf-8') as fp_git:
                fp_git.write(fields['gitProjects'].lower())
        except OSError:
            print('EX: unable to write git ' + git_projects_filename)
    else:
        if os.path.isfile(git_projects_filename):
            try:
                os.remove(git_projects_filename)
            except OSError:
                print('EX: _profile_edit unable to delete ' +
                      git_projects_filename)


def _profile_post_peertube_instances(base_dir: str, fields: {}, self,
                                     peertube_instances: []) -> None:
    """ HTTP POST save peertube instances list
    """
    peertube_instances_file = data_dir(base_dir) + '/peertube.txt'
    if fields.get('ptInstances'):
        peertube_instances.clear()
        try:
            with open(peertube_instances_file, 'w+',
                      encoding='utf-8') as fp_peertube:
                fp_peertube.write(fields['ptInstances'])
        except OSError:
            print('EX: unable to write peertube ' +
                  peertube_instances_file)
        pt_instances_list = fields['ptInstances'].split('\n')
        if pt_instances_list:
            for url in pt_instances_list:
                url = url.strip()
                if not url:
                    continue
                if url in peertube_instances:
                    continue
                peertube_instances.append(url)
    else:
        if os.path.isfile(peertube_instances_file):
            try:
                os.remove(peertube_instances_file)
            except OSError:
                print('EX: _profile_edit unable to delete ' +
                      peertube_instances_file)
        peertube_instances.clear()


def _profile_post_block_federated(base_dir: str, fields: {}, self) -> None:
    """ HTTP POST save blocking API endpoints
    """
    block_ep_new: list[str] = []
    if fields.get('blockFederated'):
        block_federated_str = fields['blockFederated']
        block_ep_new = block_federated_str.split('\n')
    if str(self.server.block_federated_endpoints) != str(block_ep_new):
        self.server.block_federated_endpoints = \
            save_block_federated_endpoints(base_dir,
                                           block_ep_new)
        if not block_ep_new:
            self.server.block_federated = []


def _profile_post_robots_txt(base_dir: str, fields: {}, self) -> None:
    """ HTTP POST save robots.txt file
    """
    new_robots_txt = ''
    if fields.get('robotsTxt'):
        new_robots_txt = fields['robotsTxt']
    if str(self.server.robots_txt) != str(new_robots_txt):
        robots_txt_filename = data_dir(base_dir) + '/robots.txt'
        if not new_robots_txt:
            self.server.robots_txt = ''
            if os.path.isfile(robots_txt_filename):
                try:
                    os.remove(robots_txt_filename)
                except OSError:
                    print('EX: _profile_post_robots_txt' +
                          ' unable to delete ' +
                          robots_txt_filename)
        else:
            try:
                with open(robots_txt_filename, 'w+',
                          encoding='utf-8') as fp_robots:
                    fp_robots.write(new_robots_txt)
            except OSError:
                print('EX: _profile_post_robots_txt unable to save ' +
                      robots_txt_filename)

            self.server.robots_txt = new_robots_txt


def _profile_post_buy_domains(base_dir: str, fields: {}, self) -> None:
    """ HTTP POST save allowed buy domains
    """
    buy_sites = {}
    if fields.get('buySitesStr'):
        buy_sites_str = fields['buySitesStr']
        buy_sites_list = buy_sites_str.split('\n')
        for site_url in buy_sites_list:
            if ' ' in site_url:
                site_url = site_url.split(' ')[-1]
                buy_icon_text = site_url.replace(site_url, '').strip()
                if not buy_icon_text:
                    buy_icon_text = site_url
            else:
                buy_icon_text = site_url
            if buy_sites.get(buy_icon_text):
                continue
            if '<' in site_url:
                continue
            if not site_url.strip():
                continue
            buy_sites[buy_icon_text] = site_url.strip()
    if str(self.server.buy_sites) != str(buy_sites):
        self.server.buy_sites = buy_sites
        buy_sites_filename = data_dir(base_dir) + '/buy_sites.json'
        if buy_sites:
            save_json(buy_sites, buy_sites_filename)
        else:
            if os.path.isfile(buy_sites_filename):
                try:
                    os.remove(buy_sites_filename)
                except OSError:
                    print('EX: unable to delete ' +
                          buy_sites_filename)


def _profile_post_crawlers_allowed(base_dir: str, fields: {}, self) -> None:
    """ HTTP POST save allowed web crawlers
    """
    crawlers_allowed: list[str] = []
    if fields.get('crawlersAllowedStr'):
        crawlers_allowed_str = fields['crawlersAllowedStr']
        crawlers_allowed_list = crawlers_allowed_str.split('\n')
        for uagent in crawlers_allowed_list:
            if uagent in crawlers_allowed:
                continue
            crawlers_allowed.append(uagent.strip())
    if str(self.server.crawlers_allowed) != str(crawlers_allowed):
        self.server.crawlers_allowed = crawlers_allowed
        crawlers_allowed_str = ''
        for uagent in crawlers_allowed:
            if crawlers_allowed_str:
                crawlers_allowed_str += ','
            crawlers_allowed_str += uagent
        set_config_param(base_dir, 'crawlersAllowed',
                         crawlers_allowed_str)


def _profile_post_blocked_user_agents(base_dir: str, fields: {}, self) -> None:
    """ HTTP POST save blocked user agents
    """
    user_agents_blocked: list[str] = []
    if fields.get('userAgentsBlockedStr'):
        user_agents_blocked_str = fields['userAgentsBlockedStr']
        user_agents_blocked_list = user_agents_blocked_str.split('\n')
        for uagent in user_agents_blocked_list:
            if uagent in user_agents_blocked:
                continue
            user_agents_blocked.append(uagent.strip())
    if str(self.server.user_agents_blocked) != str(user_agents_blocked):
        self.server.user_agents_blocked = user_agents_blocked
        user_agents_blocked_str = ''
        for uagent in user_agents_blocked:
            if user_agents_blocked_str:
                user_agents_blocked_str += ','
            user_agents_blocked_str += uagent
        set_config_param(base_dir, 'userAgentsBlocked',
                         user_agents_blocked_str)


def _profile_post_cw_lists(fields: {}, self) -> None:
    """ HTTP POST set selected content warning lists
    """
    new_lists_enabled = ''
    for name, _ in self.server.cw_lists.items():
        list_var_name = get_cw_list_variable(name)
        if fields.get(list_var_name):
            if fields[list_var_name] == 'on':
                if new_lists_enabled:
                    new_lists_enabled += ', ' + name
                else:
                    new_lists_enabled += name
    if new_lists_enabled != self.server.lists_enabled:
        self.server.lists_enabled = new_lists_enabled
        set_config_param(self.server.base_dir,
                         "listsEnabled", new_lists_enabled)


def _profile_post_allowed_instances(base_dir: str, nickname: str, domain: str,
                                    fields: {}) -> None:
    """ HTTP POST save allowed instances list
    This is the account level allow list
    """
    allowed_instances_filename = \
        acct_dir(base_dir, nickname, domain) + '/allowedinstances.txt'
    if fields.get('allowedInstances'):
        inst_filename = allowed_instances_filename
        try:
            with open(inst_filename, 'w+',
                      encoding='utf-8') as fp_inst:
                fp_inst.write(fields['allowedInstances'])
        except OSError:
            print('EX: unable to write allowed instances ' +
                  allowed_instances_filename)
    else:
        if os.path.isfile(allowed_instances_filename):
            try:
                os.remove(allowed_instances_filename)
            except OSError:
                print('EX: _profile_edit ' +
                      'unable to delete ' +
                      allowed_instances_filename)


def _profile_post_dm_instances(base_dir: str, nickname: str, domain: str,
                               fields: {}) -> None:
    """ HTTP POST Save DM allowed instances list.
    The allow list for incoming DMs,
    if the .followDMs flag file exists
    """
    dm_allowed_instances_filename = \
        acct_dir(base_dir, nickname, domain) + '/dmAllowedInstances.txt'
    if fields.get('dmAllowedInstances'):
        try:
            with open(dm_allowed_instances_filename, 'w+',
                      encoding='utf-8') as fp_dm:
                fp_dm.write(fields['dmAllowedInstances'])
        except OSError:
            print('EX: unable to write allowed DM instances ' +
                  dm_allowed_instances_filename)
    else:
        if os.path.isfile(dm_allowed_instances_filename):
            try:
                os.remove(dm_allowed_instances_filename)
            except OSError:
                print('EX: _profile_edit ' +
                      'unable to delete ' +
                      dm_allowed_instances_filename)


def _profile_post_import_theme(base_dir: str, nickname: str,
                               admin_nickname: str, fields: {}) -> None:
    """ HTTP POST import theme from file
    """
    if fields.get('importTheme'):
        if not os.path.isdir(base_dir + '/imports'):
            os.mkdir(base_dir + '/imports')
        filename_base = base_dir + '/imports/newtheme.zip'
        if os.path.isfile(filename_base):
            try:
                os.remove(filename_base)
            except OSError:
                print('EX: _profile_edit unable to delete ' +
                      filename_base)
        if nickname == admin_nickname or is_artist(base_dir, nickname):
            if import_theme(base_dir, filename_base):
                print(nickname + ' uploaded a theme')
        else:
            print('Only admin or artist can import a theme')


def _profile_post_import_follows(base_dir: str, nickname: str, domain: str,
                                 fields: {}) -> None:
    """ HTTP POST import following from file
    """
    if fields.get('importFollows'):
        filename_base = \
            acct_dir(base_dir, nickname, domain) + '/import_following.csv'
        follows_str = fields['importFollows']
        while follows_str.startswith('\n'):
            follows_str = follows_str[1:]
        try:
            with open(filename_base, 'w+',
                      encoding='utf-8') as fp_foll:
                fp_foll.write(follows_str)
        except OSError:
            print('EX: unable to write imported follows ' +
                  filename_base)


def _profile_post_import_blocks_csv(base_dir: str, nickname: str, domain: str,
                                    fields: {}) -> None:
    """ HTTP POST import blocks from csv file
    """
    if fields.get('importBlocks'):
        blocks_str = fields['importBlocks']
        while blocks_str.startswith('\n'):
            blocks_str = blocks_str[1:]
        blocks_lines = blocks_str.split('\n')
        if import_blocking_file(base_dir, nickname, domain,
                                blocks_lines):
            print('blocks imported for ' + nickname)
        else:
            print('blocks not imported for ' + nickname)


def _profile_post_auto_cw(base_dir: str, nickname: str, domain: str,
                          fields: {}, self) -> None:
    """ HTTP POST autogenerated content warnings
    """
    auto_cw_filename = \
        acct_dir(base_dir, nickname, domain) + '/autocw.txt'
    if fields.get('autoCW'):
        try:
            with open(auto_cw_filename, 'w+',
                      encoding='utf-8') as fp_auto_cw:
                fp_auto_cw.write(fields['autoCW'])
        except OSError:
            print('EX: unable to write auto CW ' +
                  auto_cw_filename)
        self.server.auto_cw_cache[nickname] = fields['autoCW'].split('\n')
    else:
        if os.path.isfile(auto_cw_filename):
            try:
                os.remove(auto_cw_filename)
            except OSError:
                print('EX: _profile_edit ' +
                      'unable to delete ' +
                      auto_cw_filename)
            self.server.auto_cw_cache[nickname]: list[str] = []


def _profile_post_autogenerated_tags(base_dir: str,
                                     nickname: str, domain: str,
                                     fields: {}) -> None:
    """ HTTP POST autogenerated tags
    """
    auto_tags_filename = \
        acct_dir(base_dir, nickname, domain) + '/autotags.txt'
    if fields.get('autoTags'):
        try:
            with open(auto_tags_filename, 'w+',
                      encoding='utf-8') as fp_auto:
                fp_auto.write(fields['autoTags'])
        except OSError:
            print('EX: unable to write auto tags ' +
                  auto_tags_filename)
    else:
        if os.path.isfile(auto_tags_filename):
            try:
                os.remove(auto_tags_filename)
            except OSError:
                print('EX: _profile_edit unable to delete ' +
                      auto_tags_filename)


def _profile_post_word_replacements(base_dir: str,
                                    nickname: str, domain: str,
                                    fields: {}) -> None:
    """ HTTP POST word replacements
    """
    switch_filename = \
        acct_dir(base_dir, nickname, domain) + '/replacewords.txt'
    if fields.get('switchwords'):
        try:
            with open(switch_filename, 'w+',
                      encoding='utf-8') as fp_switch:
                fp_switch.write(fields['switchwords'])
        except OSError:
            print('EX: unable to write switches ' +
                  switch_filename)
    else:
        if os.path.isfile(switch_filename):
            try:
                os.remove(switch_filename)
            except OSError:
                print('EX: _profile_edit ' +
                      'unable to delete ' +
                      switch_filename)


def _profile_post_filtered_words_within_bio(base_dir: str,
                                            nickname: str, domain: str,
                                            fields: {}) -> None:
    """ HTTP POST save filtered words within bio list
    """
    filter_bio_filename = \
        acct_dir(base_dir, nickname, domain) + '/filters_bio.txt'
    if fields.get('filteredWordsBio'):
        try:
            with open(filter_bio_filename, 'w+',
                      encoding='utf-8') as fp_filter:
                fp_filter.write(fields['filteredWordsBio'])
        except OSError:
            print('EX: unable to write bio filter ' +
                  filter_bio_filename)
    else:
        if os.path.isfile(filter_bio_filename):
            try:
                os.remove(filter_bio_filename)
            except OSError:
                print('EX: _profile_edit ' +
                      'unable to delete bio filter ' +
                      filter_bio_filename)


def _profile_post_filtered_words(base_dir: str, nickname: str, domain: str,
                                 fields: {}) -> None:
    """ HTTP POST save filtered words list
    """
    filter_filename = acct_dir(base_dir, nickname, domain) + '/filters.txt'
    if fields.get('filteredWords'):
        try:
            with open(filter_filename, 'w+',
                      encoding='utf-8') as fp_filter:
                fp_filter.write(fields['filteredWords'])
        except OSError:
            print('EX: unable to write filter ' +
                  filter_filename)
    else:
        if os.path.isfile(filter_filename):
            try:
                os.remove(filter_filename)
            except OSError:
                print('EX: _profile_edit ' +
                      'unable to delete filter ' +
                      filter_filename)


def _profile_post_low_bandwidth(base_dir: str, path: str,
                                nickname: str, admin_nickname: str,
                                fields: {}, self) -> None:
    """ HTTP POST low bandwidth images checkbox
    """
    if path.startswith('/users/' + admin_nickname + '/') or \
       is_artist(base_dir, nickname):
        curr_low_bandwidth = \
            get_config_param(base_dir, 'lowBandwidth')
        low_bandwidth = False
        if fields.get('lowBandwidth'):
            if fields['lowBandwidth'] == 'on':
                low_bandwidth = True
        if curr_low_bandwidth != low_bandwidth:
            set_config_param(base_dir, 'lowBandwidth',
                             low_bandwidth)
            self.server.low_bandwidth = low_bandwidth


def _profile_post_dyslexic_font(base_dir: str, path: str,
                                nickname: str, admin_nickname: str,
                                fields: {}, self,
                                theme_name: str,
                                domain: str,
                                allow_local_network_access: bool,
                                system_language: str) -> None:
    """ HTTP POST dyslexic font
    """
    if path.startswith('/users/' + admin_nickname + '/') or \
       is_artist(base_dir, nickname):
        dyslexic_font2 = False
        if fields.get('dyslexicFont'):
            if fields['dyslexicFont'] == 'on':
                dyslexic_font2 = True
        if dyslexic_font2 != self.server.dyslexic_font:
            self.server.dyslexic_font = dyslexic_font2
            set_config_param(base_dir, 'dyslexicFont',
                             self.server.dyslexic_font)
            set_theme(base_dir, theme_name, domain,
                      allow_local_network_access,
                      system_language,
                      self.server.dyslexic_font, False)


def _profile_post_grayscale_theme(base_dir: str, path: str,
                                  nickname: str, admin_nickname: str,
                                  fields: {}) -> None:
    """ HTTP POST grayscale theme
    """
    if path.startswith('/users/' + admin_nickname + '/') or \
       is_artist(base_dir, nickname):
        grayscale = False
        if fields.get('grayscale'):
            if fields['grayscale'] == 'on':
                grayscale = True
        if grayscale:
            enable_grayscale(base_dir)
        else:
            disable_grayscale(base_dir)


def _profile_post_account_type(path: str, actor_json: {}, fields: {},
                               admin_nickname: str,
                               actor_changed: bool) -> bool:
    """ HTTP POST Changes the type of account Bot/Group/Person
    """
    if fields.get('isBot'):
        if fields['isBot'] == 'on' and actor_json.get('type'):
            if actor_json['type'] != 'Service':
                actor_json['type'] = 'Service'
                actor_changed = True
    else:
        # this account is a group
        if fields.get('isGroup'):
            if fields['isGroup'] == 'on' and actor_json.get('type'):
                if actor_json['type'] != 'Group':
                    # only allow admin to create groups
                    if path.startswith('/users/' +
                                       admin_nickname + '/'):
                        actor_json['type'] = 'Group'
                        actor_changed = True
        else:
            # this account is a person (default)
            if actor_json.get('type'):
                if actor_json['type'] != 'Person':
                    actor_json['type'] = 'Person'
                    actor_changed = True
    return actor_changed


def _profile_post_notify_reactions(base_dir: str,
                                   nickname: str, domain: str,
                                   on_final_welcome_screen: bool,
                                   hide_reaction_button_active: bool,
                                   fields: {}, actor_changed: bool) -> bool:
    """ HTTP POST notify about new Reactions
    """
    notify_reactions_filename = \
        acct_dir(base_dir, nickname, domain) + '/.notifyReactions'
    if on_final_welcome_screen:
        # default setting from welcome screen
        notify_react_filename = notify_reactions_filename
        try:
            with open(notify_react_filename, 'w+',
                      encoding='utf-8') as fp_notify:
                fp_notify.write('\n')
        except OSError:
            print('EX: unable to write notify reactions ' +
                  notify_reactions_filename)
        actor_changed = True
    else:
        notify_reactions_active = False
        if fields.get('notifyReactions'):
            if fields['notifyReactions'] == 'on' and \
               not hide_reaction_button_active:
                notify_reactions_active = True
                try:
                    with open(notify_reactions_filename, 'w+',
                              encoding='utf-8') as fp_notify:
                        fp_notify.write('\n')
                except OSError:
                    print('EX: unable to write ' +
                          'notify reactions ' +
                          notify_reactions_filename)
        if not notify_reactions_active:
            if os.path.isfile(notify_reactions_filename):
                try:
                    os.remove(notify_reactions_filename)
                except OSError:
                    print('EX: _profile_edit ' +
                          'unable to delete ' +
                          notify_reactions_filename)
    return actor_changed


def _profile_post_notify_likes(on_final_welcome_screen: bool,
                               notify_likes_filename: str,
                               actor_changed: bool,
                               fields: {},
                               hide_like_button_active: bool) -> bool:
    """ HTTP POST notify about new Likes
    """
    if on_final_welcome_screen:
        # default setting from welcome screen
        try:
            with open(notify_likes_filename, 'w+',
                      encoding='utf-8') as fp_notify:
                fp_notify.write('\n')
        except OSError:
            print('EX: unable to write notify likes ' +
                  notify_likes_filename)
        actor_changed = True
    else:
        notify_likes_active = False
        if fields.get('notifyLikes'):
            if fields['notifyLikes'] == 'on' and \
               not hide_like_button_active:
                notify_likes_active = True
                try:
                    with open(notify_likes_filename, 'w+',
                              encoding='utf-8') as fp_notify:
                        fp_notify.write('\n')
                except OSError:
                    print('EX: unable to write notify likes ' +
                          notify_likes_filename)
        if not notify_likes_active:
            if os.path.isfile(notify_likes_filename):
                try:
                    os.remove(notify_likes_filename)
                except OSError:
                    print('EX: _profile_edit ' +
                          'unable to delete ' +
                          notify_likes_filename)
    return actor_changed


def _profile_post_block_military(nickname: str, fields: {}, self) -> None:
    """ HTTP POST block military instances
    """
    block_mil_instances = False
    if fields.get('blockMilitary'):
        if fields['blockMilitary'] == 'on':
            block_mil_instances = True
    if block_mil_instances:
        if not self.server.block_military.get(nickname):
            self.server.block_military[nickname] = True
            save_blocked_military(self.server.base_dir,
                                  self.server.block_military)
    else:
        if self.server.block_military.get(nickname):
            del self.server.block_military[nickname]
            save_blocked_military(self.server.base_dir,
                                  self.server.block_military)


def _profile_post_block_government(nickname: str, fields: {}, self) -> None:
    """ HTTP POST block government instances
    """
    block_gov_instances = False
    if fields.get('blockGovernment'):
        if fields['blockGovernment'] == 'on':
            block_gov_instances = True
    if block_gov_instances:
        if not self.server.block_government.get(nickname):
            self.server.block_government[nickname] = True
            save_blocked_government(self.server.base_dir,
                                    self.server.block_government)
    else:
        if self.server.block_government.get(nickname):
            del self.server.block_government[nickname]
            save_blocked_government(self.server.base_dir,
                                    self.server.block_government)


def _profile_post_block_bluesky(nickname: str, fields: {}, self) -> None:
    """ HTTP POST block bluesky bridges
    """
    block_bsky_instances = False
    if fields.get('blockBlueSky'):
        if fields['blockBlueSky'] == 'on':
            block_bsky_instances = True
    if block_bsky_instances:
        if not self.server.block_bluesky.get(nickname):
            self.server.block_bluesky[nickname] = True
            save_blocked_bluesky(self.server.base_dir,
                                 self.server.block_bluesky)
    else:
        if self.server.block_bluesky.get(nickname):
            del self.server.block_bluesky[nickname]
            save_blocked_bluesky(self.server.base_dir,
                                 self.server.block_bluesky)


def _profile_post_block_nostr(nickname: str, fields: {}, self) -> None:
    """ HTTP POST block nostr bridges
    """
    block_nostr_instances = False
    if fields.get('blockNostr'):
        if fields['blockNostr'] == 'on':
            block_nostr_instances = True
    if block_nostr_instances:
        if not self.server.block_nostr.get(nickname):
            self.server.block_nostr[nickname] = True
            save_blocked_nostr(self.server.base_dir,
                               self.server.block_nostr)
    else:
        if self.server.block_nostr.get(nickname):
            del self.server.block_nostr[nickname]
            save_blocked_nostr(self.server.base_dir,
                               self.server.block_nostr)


def _profile_post_no_reply_boosts(base_dir: str, nickname: str, domain: str,
                                  fields: {}) -> bool:
    """ HTTP POST disallow boosts of replies in inbox
    """
    no_reply_boosts_filename = \
        acct_dir(base_dir, nickname, domain) + '/.noReplyBoosts'
    no_reply_boosts = False
    if fields.get('noReplyBoosts'):
        if fields['noReplyBoosts'] == 'on':
            no_reply_boosts = True
    if no_reply_boosts:
        if not os.path.isfile(no_reply_boosts_filename):
            try:
                with open(no_reply_boosts_filename, 'w+',
                          encoding='utf-8') as fp_reply:
                    fp_reply.write('\n')
            except OSError:
                print('EX: unable to write noReplyBoosts ' +
                      no_reply_boosts_filename)
    if not no_reply_boosts:
        if os.path.isfile(no_reply_boosts_filename):
            try:
                os.remove(no_reply_boosts_filename)
            except OSError:
                print('EX: _profile_edit ' +
                      'unable to delete ' +
                      no_reply_boosts_filename)


def _profile_post_no_seen_posts(base_dir: str, nickname: str, domain: str,
                                fields: {}) -> bool:
    """ HTTP POST disallow seen posts in timelines
    """
    no_seen_posts_filename = \
        acct_dir(base_dir, nickname, domain) + '/.noSeenPosts'
    no_seen_posts = False
    if fields.get('noSeenPosts'):
        if fields['noSeenPosts'] == 'on':
            no_seen_posts = True
    if no_seen_posts:
        if not os.path.isfile(no_seen_posts_filename):
            try:
                with open(no_seen_posts_filename, 'w+',
                          encoding='utf-8') as fp_seen:
                    fp_seen.write('\n')
            except OSError:
                print('EX: unable to write noSeenPosts ' +
                      no_seen_posts_filename)
    if not no_seen_posts:
        if os.path.isfile(no_seen_posts_filename):
            try:
                os.remove(no_seen_posts_filename)
            except OSError:
                print('EX: _profile_edit ' +
                      'unable to delete ' +
                      no_seen_posts_filename)


def _profile_post_watermark_enabled(base_dir: str,
                                    nickname: str, domain: str,
                                    fields: {}) -> bool:
    """ HTTP POST apply watermark to image attachments
    """
    watermark_enabled_filename = \
        acct_dir(base_dir, nickname, domain) + '/.watermarkEnabled'
    watermark_enabled = False
    if fields.get('watermarkEnabled'):
        if fields['watermarkEnabled'] == 'on':
            watermark_enabled = True
    if watermark_enabled:
        if not os.path.isfile(watermark_enabled_filename):
            try:
                with open(watermark_enabled_filename, 'w+',
                          encoding='utf-8') as fp_wm:
                    fp_wm.write('\n')
            except OSError:
                print('EX: unable to write watermarkEnabled ' +
                      watermark_enabled_filename)
    if not watermark_enabled:
        if os.path.isfile(watermark_enabled_filename):
            try:
                os.remove(watermark_enabled_filename)
            except OSError:
                print('EX: _profile_edit ' +
                      'unable to delete ' +
                      watermark_enabled_filename)


def _profile_post_hide_follows(base_dir: str, nickname: str, domain: str,
                               actor_json: {}, fields: {}, self,
                               actor_changed: bool,
                               premium: bool) -> bool:
    """ HTTP POST hide follows checkbox
    This hides follows from unauthorized viewers
    """
    hide_follows_filename = \
        acct_dir(base_dir, nickname, domain) + '/.hideFollows'
    hide_follows = premium
    if fields.get('hideFollows'):
        if fields['hideFollows'] == 'on':
            hide_follows = True
    if hide_follows:
        self.server.hide_follows[nickname] = True
        actor_json['hideFollows'] = True
        actor_changed = True
        if not os.path.isfile(hide_follows_filename):
            try:
                with open(hide_follows_filename, 'w+',
                          encoding='utf-8') as fp_hide:
                    fp_hide.write('\n')
            except OSError:
                print('EX: unable to write hideFollows ' +
                      hide_follows_filename)
    if not hide_follows:
        actor_json['hideFollows'] = False
        if self.server.hide_follows.get(nickname):
            del self.server.hide_follows[nickname]
            actor_changed = True
        if os.path.isfile(hide_follows_filename):
            try:
                os.remove(hide_follows_filename)
            except OSError:
                print('EX: _profile_post_hide_follows ' +
                      'unable to delete ' +
                      hide_follows_filename)
    return actor_changed


def _profile_post_hide_recent_posts(base_dir: str, nickname: str, domain: str,
                                    actor_json: {}, fields: {}, self,
                                    actor_changed: bool,
                                    premium: bool) -> bool:
    """ HTTP POST hide recent public posts checkbox
    This hides public posts from unauthorized viewers
    """
    hide_recent_posts_filename = \
        acct_dir(base_dir, nickname, domain) + '/.hideRecentPosts'
    hide_recent_posts = premium
    if fields.get('hideRecentPosts'):
        if fields['hideRecentPosts'] == 'on':
            hide_recent_posts = True
    if hide_recent_posts:
        self.server.hide_recent_posts[nickname] = True
        actor_json['hideRecentPosts'] = True
        actor_changed = True
        if not os.path.isfile(hide_recent_posts_filename):
            try:
                with open(hide_recent_posts_filename, 'w+',
                          encoding='utf-8') as fp_hide:
                    fp_hide.write('\n')
            except OSError:
                print('EX: unable to write hideRecentPosts ' +
                      hide_recent_posts_filename)
    if not hide_recent_posts:
        actor_json['hideRecentPosts'] = False
        if self.server.hide_recent_posts.get(nickname):
            del self.server.hide_recent_posts[nickname]
            actor_changed = True
        if os.path.isfile(hide_recent_posts_filename):
            try:
                os.remove(hide_recent_posts_filename)
            except OSError:
                print('EX: _profile_post_hide_recent_posts ' +
                      'unable to delete ' +
                      hide_recent_posts_filename)
    return actor_changed


def _profile_post_mutuals_replies(account_dir: str, fields: {}) -> None:
    """ HTTP POST show replies only from mutuals checkbox
    """
    show_replies_mutuals = False
    if fields.get('repliesFromMutualsOnly'):
        if fields['repliesFromMutualsOnly'] == 'on':
            show_replies_mutuals = True
    show_replies_mutuals_file = account_dir + '/.repliesFromMutualsOnly'
    if os.path.isfile(show_replies_mutuals_file):
        if not show_replies_mutuals:
            try:
                os.remove(show_replies_mutuals_file)
            except OSError:
                print('EX: unable to remove repliesFromMutualsOnly file ' +
                      show_replies_mutuals_file)
    else:
        if show_replies_mutuals:
            try:
                with open(show_replies_mutuals_file, 'w+',
                          encoding='utf-8') as fp_replies:
                    fp_replies.write('\n')
            except OSError:
                print('EX: unable to write repliesFromMutualsOnly file ' +
                      show_replies_mutuals_file)


def _profile_post_only_follower_replies(fields: {},
                                        account_dir: str) -> None:
    """ HTTP POST show replies only from followers checkbox
    """
    show_replies_followers = False
    if fields.get('repliesFromFollowersOnly'):
        if fields['repliesFromFollowersOnly'] == 'on':
            show_replies_followers = True
    show_replies_followers_file = account_dir + '/.repliesFromFollowersOnly'
    if os.path.isfile(show_replies_followers_file):
        if not show_replies_followers:
            try:
                os.remove(show_replies_followers_file)
            except OSError:
                print('EX: unable to remove ' +
                      'repliesFromFollowersOnly file ' +
                      show_replies_followers_file)
    else:
        if show_replies_followers:
            try:
                with open(show_replies_followers_file, 'w+',
                          encoding='utf-8') as fp_replies:
                    fp_replies.write('\n')
            except OSError:
                print('EX: unable to write ' +
                      'repliesFromFollowersOnly file ' +
                      show_replies_followers_file)


def _profile_post_show_quote_toots(fields: {}, account_dir: str) -> None:
    """ HTTP POST show quote toots checkbox on edit profile
    """
    show_quote_toots = False
    if fields.get('showQuotes'):
        if fields['showQuotes'] == 'on':
            show_quote_toots = True
    show_quote_toots_file = account_dir + '/.allowQuotes'
    if os.path.isfile(show_quote_toots_file):
        if not show_quote_toots:
            try:
                os.remove(show_quote_toots_file)
            except OSError:
                print('EX: unable to remove allowQuotes file ' +
                      show_quote_toots_file)
    else:
        if show_quote_toots:
            try:
                with open(show_quote_toots_file, 'w+',
                          encoding='utf-8') as fp_quotes:
                    fp_quotes.write('\n')
            except OSError:
                print('EX: unable to write allowQuotes file ' +
                      show_quote_toots_file)


def _profile_post_show_questions(fields: {}, account_dir: str) -> None:
    """ HTTP POST show poll/vote/question posts checkbox
    """
    show_vote_posts = False
    if fields.get('showVotes'):
        if fields['showVotes'] == 'on':
            show_vote_posts = True
    show_vote_file = account_dir + '/.noVotes'
    if os.path.isfile(show_vote_file):
        if show_vote_posts:
            try:
                os.remove(show_vote_file)
            except OSError:
                print('EX: unable to remove noVotes file ' +
                      show_vote_file)
    else:
        if not show_vote_posts:
            try:
                with open(show_vote_file, 'w+',
                          encoding='utf-8') as fp_votes:
                    fp_votes.write('\n')
            except OSError:
                print('EX: unable to write noVotes file ' +
                      show_vote_file)


def _profile_post_reverse_timelines(base_dir: str, nickname: str,
                                    fields: {}, self) -> None:
    """ HTTP POST reverse timelines checkbox
    """
    reverse = False
    if fields.get('reverseTimelines'):
        if fields['reverseTimelines'] == 'on':
            reverse = True
            if nickname not in self.server.reverse_sequence:
                self.server.reverse_sequence.append(nickname)
            save_reverse_timeline(base_dir,
                                  self.server.reverse_sequence)
    if not reverse:
        if nickname in self.server.reverse_sequence:
            self.server.reverse_sequence.remove(nickname)
            save_reverse_timeline(base_dir,
                                  self.server.reverse_sequence)


def _profile_post_bold_reading(base_dir: str,
                               nickname: str, domain: str,
                               fields: {}, self) -> None:
    """ HTTP POST bold reading checkbox
    """
    bold_reading_filename = \
        acct_dir(base_dir, nickname, domain) + '/.boldReading'
    bold_reading = False
    if fields.get('boldReading'):
        if fields['boldReading'] == 'on':
            bold_reading = True
            self.server.bold_reading[nickname] = True
            try:
                with open(bold_reading_filename, 'w+',
                          encoding='utf-8') as fp_bold:
                    fp_bold.write('\n')
            except OSError:
                print('EX: unable to write bold reading ' +
                      bold_reading_filename)
    if not bold_reading:
        if self.server.bold_reading.get(nickname):
            del self.server.bold_reading[nickname]
        if os.path.isfile(bold_reading_filename):
            try:
                os.remove(bold_reading_filename)
            except OSError:
                print('EX: _profile_edit unable to delete ' +
                      bold_reading_filename)


def _profile_post_hide_reaction_button2(base_dir: str,
                                        nickname: str, domain: str,
                                        fields: {}) -> None:
    """ HTTP POST hide Reaction button
    """
    hide_reaction_button_file = \
        acct_dir(base_dir, nickname, domain) + '/.hideReactionButton'
    notify_reactions_filename = \
        acct_dir(base_dir, nickname, domain) + '/.notifyReactions'
    hide_reaction_button_active = False
    if fields.get('hideReactionButton'):
        if fields['hideReactionButton'] == 'on':
            hide_reaction_button_active = True
            try:
                with open(hide_reaction_button_file, 'w+',
                          encoding='utf-8') as fp_hide:
                    fp_hide.write('\n')
            except OSError:
                print('EX: unable to write hide reaction ' +
                      hide_reaction_button_file)
            # remove notify Reaction selection
            if os.path.isfile(notify_reactions_filename):
                try:
                    os.remove(notify_reactions_filename)
                except OSError:
                    print('EX: _profile_edit unable to delete ' +
                          notify_reactions_filename)
    if not hide_reaction_button_active:
        if os.path.isfile(hide_reaction_button_file):
            try:
                os.remove(hide_reaction_button_file)
            except OSError:
                print('EX: _profile_edit unable to delete ' +
                      hide_reaction_button_file)


def _profile_post_minimize_images(base_dir: str, nickname: str, domain: str,
                                  fields: {},
                                  min_images_for_accounts: []) -> None:
    """ HTTP POST Minimize all images from edit profile screen
    """
    minimize_all_images = False
    if fields.get('minimizeAllImages'):
        if fields['minimizeAllImages'] == 'on':
            minimize_all_images = True
            min_img_acct = min_images_for_accounts
            set_minimize_all_images(base_dir,
                                    nickname, domain,
                                    True, min_img_acct)
            print('min_images_for_accounts: ' +
                  str(min_img_acct))
    if not minimize_all_images:
        min_img_acct = min_images_for_accounts
        set_minimize_all_images(base_dir,
                                nickname, domain,
                                False, min_img_acct)
        print('min_images_for_accounts: ' +
              str(min_img_acct))


def _profile_post_hide_like_button2(base_dir: str, nickname: str, domain: str,
                                    fields: {}) -> None:
    """ HTTP POST hide Like button
    """
    hide_like_button_file = \
        acct_dir(base_dir, nickname, domain) + '/.hideLikeButton'
    notify_likes_filename = \
        acct_dir(base_dir, nickname, domain) + '/.notifyLikes'
    hide_like_button_active = False
    if fields.get('hideLikeButton'):
        if fields['hideLikeButton'] == 'on':
            hide_like_button_active = True
            try:
                with open(hide_like_button_file, 'w+',
                          encoding='utf-8') as rfil:
                    rfil.write('\n')
            except OSError:
                print('EX: unable to write hide like ' +
                      hide_like_button_file)
            # remove notify likes selection
            if os.path.isfile(notify_likes_filename):
                try:
                    os.remove(notify_likes_filename)
                except OSError:
                    print('EX: _profile_edit unable to delete ' +
                          notify_likes_filename)
    if not hide_like_button_active:
        if os.path.isfile(hide_like_button_file):
            try:
                os.remove(hide_like_button_file)
            except OSError:
                print('EX: _profile_edit unable to delete ' +
                      hide_like_button_file)


def _profile_post_remove_retweets(base_dir: str, nickname: str, domain: str,
                                  fields: {}) -> None:
    """ HTTP POST remove Twitter retweets
    """
    remove_twitter_filename = \
        acct_dir(base_dir, nickname, domain) + '/.removeTwitter'
    remove_twitter_active = False
    if fields.get('removeTwitter'):
        if fields['removeTwitter'] == 'on':
            remove_twitter_active = True
            try:
                with open(remove_twitter_filename, 'w+',
                          encoding='utf-8') as fp_remove:
                    fp_remove.write('\n')
            except OSError:
                print('EX: unable to write remove twitter ' +
                      remove_twitter_filename)
    if not remove_twitter_active:
        if os.path.isfile(remove_twitter_filename):
            try:
                os.remove(remove_twitter_filename)
            except OSError:
                print('EX: _profile_edit unable to delete ' +
                      remove_twitter_filename)


def _profile_post_dms_from_followers(base_dir: str, nickname: str, domain: str,
                                     on_final_welcome_screen: str, fields: {},
                                     actor_changed: bool) -> bool:
    """ HTTP POST only receive DMs from accounts you follow
    """
    follow_dms_filename = \
        acct_dir(base_dir, nickname, domain) + '/.followDMs'
    if on_final_welcome_screen:
        # initial default setting created via
        # the welcome screen
        try:
            with open(follow_dms_filename, 'w+',
                      encoding='utf-8') as fp_foll:
                fp_foll.write('\n')
        except OSError:
            print('EX: unable to write follow DMs ' +
                  follow_dms_filename)
        actor_changed = True
    else:
        follow_dms_active = False
        if fields.get('followDMs'):
            if fields['followDMs'] == 'on':
                follow_dms_active = True
                try:
                    with open(follow_dms_filename, 'w+',
                              encoding='utf-8') as fp_foll:
                        fp_foll.write('\n')
                except OSError:
                    print('EX: unable to write follow DMs 2 ' +
                          follow_dms_filename)
        if not follow_dms_active:
            if os.path.isfile(follow_dms_filename):
                try:
                    os.remove(follow_dms_filename)
                except OSError:
                    print('EX: _profile_edit unable to delete ' +
                          follow_dms_filename)
    return actor_changed


def _profile_post_remove_custom_font(base_dir: str, nickname: str, domain: str,
                                     system_language: str, admin_nickname: str,
                                     dyslexic_font: bool,
                                     path: str, fields: {}, self) -> None:
    """ HTTP POST remove a custom font
    """
    if not fields.get('removeCustomFont'):
        return
    if (fields['removeCustomFont'] == 'on' and
        (is_artist(base_dir, nickname) or
         path.startswith('/users/' + admin_nickname + '/'))):
        font_ext = ('woff', 'woff2', 'otf', 'ttf')
        for ext in font_ext:
            if os.path.isfile(base_dir + '/fonts/custom.' + ext):
                try:
                    os.remove(base_dir + '/fonts/custom.' + ext)
                except OSError:
                    print('EX: _profile_edit unable to delete ' +
                          base_dir + '/fonts/custom.' + ext)
            if os.path.isfile(base_dir +
                              '/fonts/custom.' + ext + '.etag'):
                try:
                    os.remove(base_dir +
                              '/fonts/custom.' + ext + '.etag')
                except OSError:
                    print('EX: _profile_edit ' +
                          'unable to delete ' +
                          base_dir + '/fonts/custom.' +
                          ext + '.etag')
        curr_theme = get_theme(base_dir)
        if curr_theme:
            self.server.theme_name = curr_theme
            allow_local_network_access = self.server.allow_local_network_access
            set_theme(base_dir, curr_theme, domain,
                      allow_local_network_access,
                      system_language,
                      dyslexic_font, False)
            self.server.text_mode_banner = get_text_mode_banner(base_dir)
            self.server.iconsCache = {}
            self.server.fontsCache = {}
            self.server.show_publish_as_icon = \
                get_config_param(base_dir, 'showPublishAsIcon')
            self.server.full_width_tl_button_header = \
                get_config_param(base_dir, 'fullWidthTimelineButtonHeader')
            self.server.icons_as_buttons = \
                get_config_param(base_dir, 'iconsAsButtons')
            self.server.rss_icon_at_top = \
                get_config_param(base_dir, 'rssIconAtTop')
            self.server.publish_button_at_top = \
                get_config_param(base_dir, 'publishButtonAtTop')


def _profile_post_keep_dms(base_dir: str,
                           nickname: str, domain: str,
                           fields: {},
                           actor_changed: bool) -> bool:
    """ HTTP POST keep DMs during post expiry
    """
    expire_keep_dms = False
    if fields.get('expiryKeepDMs'):
        if fields['expiryKeepDMs'] == 'on':
            expire_keep_dms = True
    curr_keep_dms = get_post_expiry_keep_dms(base_dir, nickname, domain)
    if curr_keep_dms != expire_keep_dms:
        set_post_expiry_keep_dms(base_dir, nickname, domain,
                                 expire_keep_dms)
        actor_changed = True
    return actor_changed


def _profile_post_reject_spam_actors(base_dir: str,
                                     nickname: str, domain: str,
                                     fields: {}) -> None:
    """ HTTP POST reject spam actors
    """
    reject_spam_actors = False
    if fields.get('rejectSpamActors'):
        if fields['rejectSpamActors'] == 'on':
            reject_spam_actors = True
    curr_reject_spam_actors = False
    actor_spam_filter_filename = \
        acct_dir(base_dir, nickname, domain) + '/.reject_spam_actors'
    if os.path.isfile(actor_spam_filter_filename):
        curr_reject_spam_actors = True
    if reject_spam_actors != curr_reject_spam_actors:
        if reject_spam_actors:
            try:
                with open(actor_spam_filter_filename, 'w+',
                          encoding='utf-8') as fp_spam:
                    fp_spam.write('\n')
            except OSError:
                print('EX: unable to write reject spam actors')
        else:
            try:
                os.remove(actor_spam_filter_filename)
            except OSError:
                print('EX: unable to remove reject spam actors')


def _profile_post_approve_followers(on_final_welcome_screen: bool,
                                    actor_json: {}, fields: {},
                                    actor_changed: bool,
                                    premium: bool, base_dir: str,
                                    nickname: str, domain: str) -> bool:
    """ HTTP POST approve followers and handle premium account flag
    """
    if on_final_welcome_screen:
        # Default setting created via the welcome screen
        actor_json['manuallyApprovesFollowers'] = True
        actor_changed = True
        set_premium_account(base_dir, nickname, domain, False)
    else:
        approve_followers = premium
        if fields.get('approveFollowers'):
            if fields['approveFollowers'] == 'on':
                approve_followers = True

        premium_activated = False
        if fields.get('premiumAccount'):
            if fields['premiumAccount'] == 'on':
                # turn on premium flag
                set_premium_account(base_dir, nickname, domain, True)
                approve_followers = True
                premium_activated = True
        if premium and not premium_activated:
            # turn off premium flag
            set_premium_account(base_dir, nickname, domain, False)

        if approve_followers != actor_json['manuallyApprovesFollowers']:
            actor_json['manuallyApprovesFollowers'] = approve_followers
            actor_changed = True
    return actor_changed


def _profile_post_shared_item_federation_domains(base_dir: str, fields: {},
                                                 self) -> None:
    """ HTTP POST shared item federation domains
    """
    # shared item federation domains
    si_domain_updated = False
    fed_domains_variable = "sharedItemsFederatedDomains"
    fed_domains_str = get_config_param(base_dir, fed_domains_variable)
    if not fed_domains_str:
        fed_domains_str = ''
    shared_items_form_str = ''
    if fields.get('shareDomainList'):
        shared_it_list = fed_domains_str.split(',')
        for shared_federated_domain in shared_it_list:
            shared_items_form_str += shared_federated_domain.strip() + '\n'

        share_domain_list = fields['shareDomainList']
        if share_domain_list != shared_items_form_str:
            shared_items_form_str2 = share_domain_list.replace('\n', ',')
            shared_items_field = "sharedItemsFederatedDomains"
            set_config_param(base_dir,
                             shared_items_field,
                             shared_items_form_str2)
            si_domain_updated = True
    else:
        if fed_domains_str:
            shared_items_field = "sharedItemsFederatedDomains"
            set_config_param(base_dir,
                             shared_items_field, '')
            si_domain_updated = True
    if si_domain_updated:
        si_domains = shared_items_form_str.split('\n')
        si_tokens = self.server.shared_item_federation_tokens
        self.server.shared_items_federated_domains = si_domains
        domain_full = self.server.domain_full
        base_dir = self.server.base_dir
        self.server.shared_item_federation_tokens = \
            merge_shared_item_tokens(base_dir, domain_full,
                                     si_domains, si_tokens)


def _profile_post_broch_mode(base_dir: str, domain_full: str,
                             fields: {}) -> None:
    """ HTTP POST broch mode
    """
    broch_mode = False
    if fields.get('brochMode'):
        if fields['brochMode'] == 'on':
            broch_mode = True
    curr_broch_mode = get_config_param(base_dir, "brochMode")
    if broch_mode != curr_broch_mode:
        set_broch_mode(base_dir, domain_full, broch_mode)
        set_config_param(base_dir, 'brochMode',
                         broch_mode)


def _profile_post_verify_all_signatures(base_dir: str, fields: {},
                                        self) -> None:
    """ HTTP POST verify all signatures
    """
    verify_all_signatures = False
    if fields.get('verifyallsignatures'):
        if fields['verifyallsignatures'] == 'on':
            verify_all_signatures = True
    self.server.verify_all_signatures = verify_all_signatures
    set_config_param(base_dir, "verifyAllSignatures",
                     verify_all_signatures)


def _profile_post_show_nodeinfo_version(base_dir: str, fields: {},
                                        self) -> None:
    """ HTTP POST show nodeinfo version
    """
    show_node_info_version = False
    if fields.get('showNodeInfoVersion'):
        if fields['showNodeInfoVersion'] == 'on':
            show_node_info_version = True
    self.server.show_node_info_version = show_node_info_version
    set_config_param(base_dir,
                     "showNodeInfoVersion",
                     show_node_info_version)


def _profile_post_show_nodeinfo(base_dir: str, fields: {}, self) -> None:
    """ HTTP POST Show number of accounts within nodeinfo
    """
    show_node_info_accounts = False
    if fields.get('showNodeInfoAccounts'):
        if fields['showNodeInfoAccounts'] == 'on':
            show_node_info_accounts = True
    self.server.show_node_info_accounts = show_node_info_accounts
    set_config_param(base_dir,
                     "showNodeInfoAccounts",
                     show_node_info_accounts)


def _profile_post_bio(actor_json: {}, fields: {},
                      base_dir: str, http_prefix: str,
                      nickname: str, domain: str, domain_full: str,
                      system_language: str, translate: {},
                      actor_changed: bool,
                      redirect_path: str,
                      check_name_and_bio: bool) -> bool:
    """ HTTP POST change user bio
    """
    featured_tags = get_featured_hashtags(actor_json) + ' '
    actor_json['tag']: list[dict] = []
    if fields.get('bio'):
        if fields['bio'] != actor_json['summary']:
            bio_str = remove_html(fields['bio'])
            if not is_filtered(base_dir,
                               nickname, domain, bio_str,
                               system_language):
                actor_tags = {}
                actor_json['summary'] = \
                    add_html_tags(base_dir,
                                  http_prefix,
                                  nickname,
                                  domain_full,
                                  bio_str, [], actor_tags,
                                  translate)
                if actor_tags:
                    for _, tag in actor_tags.items():
                        if tag['name'] + ' ' in featured_tags:
                            continue
                        actor_json['tag'].append(tag)
                actor_changed = True
            else:
                if check_name_and_bio:
                    redirect_path = '/welcome_profile'
    else:
        if check_name_and_bio:
            redirect_path = '/welcome_profile'
    set_featured_hashtags(actor_json, featured_tags, True)
    return actor_changed, redirect_path


def _profile_post_alsoknownas(actor_json: {}, fields: {},
                              actor_changed: bool) -> bool:
    """ HTTP POST Other accounts (alsoKnownAs)
    """
    also_known_as: list[str] = []
    if actor_json.get('alsoKnownAs'):
        also_known_as = actor_json['alsoKnownAs']
    if fields.get('alsoKnownAs'):
        also_known_as_str = ''
        also_known_as_ctr = 0
        for alt_actor in also_known_as:
            if also_known_as_ctr > 0:
                also_known_as_str += ', '
            also_known_as_str += alt_actor
            also_known_as_ctr += 1
        if fields['alsoKnownAs'] != also_known_as_str and \
           '://' in fields['alsoKnownAs'] and \
           '@' not in fields['alsoKnownAs'] and \
           '.' in fields['alsoKnownAs']:
            if ';' in fields['alsoKnownAs']:
                fields['alsoKnownAs'] = \
                    fields['alsoKnownAs'].replace(';', ',')
            new_also_known_as = fields['alsoKnownAs'].split(',')
            also_known_as: list[str] = []
            for alt_actor in new_also_known_as:
                alt_actor = alt_actor.strip()
                if resembles_url(alt_actor):
                    if alt_actor not in also_known_as:
                        also_known_as.append(alt_actor)
            actor_json['alsoKnownAs'] = also_known_as
            actor_changed = True
    else:
        if also_known_as:
            del actor_json['alsoKnownAs']
            actor_changed = True
    return actor_changed


def _profile_post_featured_hashtags(actor_json: {}, fields: {},
                                    actor_changed: bool) -> bool:
    """ HTTP POST featured hashtags on edit profile screen
    """
    featured_hashtags = get_featured_hashtags(actor_json)
    if fields.get('featuredHashtags'):
        fields['featuredHashtags'] = remove_html(fields['featuredHashtags'])
        if featured_hashtags != fields['featuredHashtags']:
            set_featured_hashtags(actor_json,
                                  fields['featuredHashtags'])
            actor_changed = True
    else:
        if featured_hashtags:
            set_featured_hashtags(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_occupation(actor_json: {}, fields: {},
                             actor_changed: bool) -> bool:
    """ HTTP POST occupation on edit profile screen
    """
    occupation_name = get_occupation_name(actor_json)
    if fields.get('occupationName'):
        fields['occupationName'] = remove_html(fields['occupationName'])
        if occupation_name != fields['occupationName']:
            set_occupation_name(actor_json,
                                fields['occupationName'])
            actor_changed = True
    else:
        if occupation_name:
            set_occupation_name(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_moved(actor_json: {}, fields: {},
                        actor_changed: bool,
                        send_move_activity: bool) -> bool:
    """ HTTP POST account moved to new address
    """
    moved_to = ''
    if actor_json.get('movedTo'):
        moved_to = actor_json['movedTo']
    if fields.get('movedTo'):
        if fields['movedTo'] != moved_to and resembles_url(fields['movedTo']):
            actor_json['movedTo'] = fields['movedTo']
            send_move_activity = True
            actor_changed = True
    else:
        if moved_to:
            del actor_json['movedTo']
            actor_changed = True
    return actor_changed, send_move_activity


def _profile_post_gemini_link(actor_json: {}, fields: {},
                              actor_changed: bool) -> bool:
    """ HTTP POST change gemini link
    """
    current_gemini_link = get_gemini_link(actor_json)
    if fields.get('geminiLink'):
        if fields['geminiLink'] != current_gemini_link:
            set_gemini_link(actor_json,
                            fields['geminiLink'])
            actor_changed = True
    else:
        if current_gemini_link:
            set_gemini_link(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_website(curr_session, base_dir: str, http_prefix: str,
                          nickname: str, domain: str,
                          actor_json: {}, fields: {},
                          actor_changed: bool,
                          translate: {}, debug: bool,
                          mitm_servers: []) -> bool:
    """ HTTP POST change website
    """
    current_website = get_website(actor_json, translate)
    if fields.get('websiteUrl'):
        if fields['websiteUrl'] != current_website:
            set_website(actor_json,
                        fields['websiteUrl'],
                        translate)
            actor_changed = True
        site_is_verified(curr_session,
                         base_dir,
                         http_prefix,
                         nickname, domain,
                         fields['websiteUrl'],
                         True, debug,
                         mitm_servers)
    else:
        if current_website:
            set_website(actor_json, '', translate)
            actor_changed = True
    return actor_changed


def _profile_post_donation_link(actor_json: {}, fields: {},
                                actor_changed: bool) -> bool:
    """ HTTP POST change donation link
    """
    current_donate_url = get_donation_url(actor_json)
    if fields.get('donateUrl'):
        if fields['donateUrl'] != current_donate_url:
            set_donation_url(actor_json,
                             fields['donateUrl'])
            actor_changed = True
    else:
        if current_donate_url:
            set_donation_url(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_pgp_fingerprint(actor_json: {}, fields: {},
                                  actor_changed: bool) -> bool:
    """ HTTP POST change PGP fingerprint
    """
    currentpgp_fingerprint = get_pgp_fingerprint(actor_json)
    if fields.get('openpgp'):
        if fields['openpgp'] != currentpgp_fingerprint:
            set_pgp_fingerprint(actor_json, fields['openpgp'])
            actor_changed = True
    else:
        if currentpgp_fingerprint:
            set_pgp_fingerprint(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_pgp_pubkey(actor_json: {}, fields: {},
                             actor_changed: bool) -> bool:
    """ HTTP POST change PGP public key
    """
    currentpgp_pub_key = get_pgp_pub_key(actor_json)
    if fields.get('pgp'):
        if fields['pgp'] != currentpgp_pub_key:
            set_pgp_pub_key(actor_json, fields['pgp'])
            actor_changed = True
    else:
        if currentpgp_pub_key:
            set_pgp_pub_key(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_enigma_pubkey(actor_json: {}, fields: {},
                                actor_changed: bool) -> bool:
    """ HTTP POST change Enigma public key
    """
    currentenigma_pub_key = get_enigma_pub_key(actor_json)
    if fields.get('enigmapubkey'):
        if fields['enigmapubkey'] != currentenigma_pub_key:
            set_enigma_pub_key(actor_json,
                               fields['enigmapubkey'])
            actor_changed = True
    else:
        if currentenigma_pub_key:
            set_enigma_pub_key(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_ntfy_topic(base_dir: str, nickname: str, domain: str,
                             fields: {}) -> None:
    """ HTTP POST change ntfy topic
    """
    if fields.get('ntfyTopic'):
        ntfy_topic_file = acct_dir(base_dir, nickname, domain) + '/.ntfy_topic'
        try:
            with open(ntfy_topic_file, 'w+',
                      encoding='utf-8') as fp_ntfy:
                fp_ntfy.write(fields['ntfyTopic'])
        except OSError:
            print('EX: unable to save ntfy topic ' +
                  ntfy_topic_file)


def _profile_post_ntfy_url(base_dir: str, nickname: str, domain: str,
                           fields: {}) -> None:
    """ HTTP POST change ntfy url
    """
    if fields.get('ntfyUrl'):
        ntfy_url_file = acct_dir(base_dir, nickname, domain) + '/.ntfy_url'
        try:
            with open(ntfy_url_file, 'w+',
                      encoding='utf-8') as fp_ntfy:
                fp_ntfy.write(fields['ntfyUrl'])
        except OSError:
            print('EX: unable to save ntfy url ' +
                  ntfy_url_file)


def _profile_post_cwtch_address(fields: {}, actor_json: {},
                                actor_changed: bool) -> bool:
    """ HTTP POST change cwtch address
    """
    current_cwtch_address = get_cwtch_address(actor_json)
    if fields.get('cwtchAddress'):
        if fields['cwtchAddress'] != current_cwtch_address:
            set_cwtch_address(actor_json,
                              fields['cwtchAddress'])
            actor_changed = True
    else:
        if current_cwtch_address:
            set_cwtch_address(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_briar_address(fields: {}, actor_json: {},
                                actor_changed: bool) -> bool:
    """ HTTP POST change briar address
    """
    current_briar_address = get_briar_address(actor_json)
    if fields.get('briarAddress'):
        if fields['briarAddress'] != current_briar_address:
            set_briar_address(actor_json,
                              fields['briarAddress'])
            actor_changed = True
    else:
        if current_briar_address:
            set_briar_address(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_tox_address(fields: {}, actor_json: {},
                              actor_changed: bool) -> bool:
    """ HTTP POST change tox address
    """
    current_tox_address = get_tox_address(actor_json)
    if fields.get('toxAddress'):
        if fields['toxAddress'] != current_tox_address:
            set_tox_address(actor_json,
                            fields['toxAddress'])
            actor_changed = True
    else:
        if current_tox_address:
            set_tox_address(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_birthday(fields: {}, actor_json: {},
                           actor_changed: bool) -> bool:
    """ HTTP POST birthday on edit profile screen
    """
    birth_date = ''
    if actor_json.get('vcard:bday'):
        birth_date = actor_json['vcard:bday']
    if fields.get('birthDate'):
        if fields['birthDate'] != birth_date:
            new_birth_date = fields['birthDate']
            if '-' in new_birth_date and len(new_birth_date.split('-')) == 3:
                # set birth date
                actor_json['vcard:bday'] = new_birth_date
                actor_changed = True
    else:
        # set birth date
        if birth_date:
            actor_json['vcard:bday'] = ''
            actor_changed = True
    return actor_changed


def _profile_post_max_preview(base_dir: str, nickname: str, domain: str,
                              fields: {}) -> None:
    """ HTTP POST set maximum preview posts on profile screen
    """
    max_profile_posts = get_max_profile_posts(base_dir, nickname, domain, 20)
    if fields.get('maxRecentProfilePosts'):
        if fields['maxRecentProfilePosts'] != str(max_profile_posts):
            max_profile_posts = fields['maxRecentProfilePosts']
            set_max_profile_posts(base_dir, nickname, domain,
                                  max_profile_posts)
    else:
        set_max_profile_posts(base_dir, nickname, domain, 20)


def _profile_post_expiry(base_dir: str, nickname: str, domain: str,
                         fields: {}, actor_changed: bool) -> bool:
    """ HTTP POST set post expiry period in days
    """
    post_expiry_period_days = get_post_expiry_days(base_dir, nickname, domain)
    if fields.get('postExpiryPeriod'):
        if fields['postExpiryPeriod'] != str(post_expiry_period_days):
            post_expiry_period_days = fields['postExpiryPeriod']
            set_post_expiry_days(base_dir, nickname, domain,
                                 post_expiry_period_days)
            actor_changed = True
    else:
        if post_expiry_period_days > 0:
            set_post_expiry_days(base_dir, nickname, domain, 0)
            actor_changed = True
    return actor_changed


def _profile_post_time_zone(base_dir: str, nickname: str, domain: str,
                            fields: {}, actor_changed: bool, self) -> bool:
    """ HTTP POST change time zone
    """
    timezone = get_account_timezone(base_dir, nickname, domain)
    if fields.get('timeZone'):
        if fields['timeZone'] != timezone:
            set_account_timezone(base_dir,
                                 nickname, domain,
                                 fields['timeZone'])
            self.server.account_timezone[nickname] = fields['timeZone']
            actor_changed = True
    else:
        if timezone:
            set_account_timezone(base_dir,
                                 nickname, domain, '')
            del self.server.account_timezone[nickname]
            actor_changed = True
    return actor_changed


def _profile_post_show_languages(actor_json: {}, fields: {},
                                 actor_changed: bool) -> bool:
    """ HTTP POST change Languages shown
    """
    current_show_languages = get_actor_languages(actor_json)
    if fields.get('showLanguages'):
        if fields['showLanguages'] != current_show_languages:
            set_actor_languages(actor_json,
                                fields['showLanguages'])
            actor_changed = True
    else:
        if current_show_languages:
            set_actor_languages(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_blog_address(curr_session,
                               base_dir: str, http_prefix: str,
                               nickname: str, domain: str,
                               actor_json: {}, fields: {},
                               actor_changed: bool,
                               debug: bool,
                               mitm_servers: []) -> bool:
    """ HTTP POST change blog address
    """
    current_blog_address = get_blog_address(actor_json)
    if fields.get('blogAddress'):
        if fields['blogAddress'] != current_blog_address:
            set_blog_address(actor_json,
                             fields['blogAddress'])
            actor_changed = True
        site_is_verified(curr_session,
                         base_dir, http_prefix,
                         nickname, domain,
                         fields['blogAddress'],
                         True, debug, mitm_servers)
    else:
        if current_blog_address:
            set_blog_address(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_ssb_address(actor_json: {}, fields: {},
                              actor_changed: bool) -> bool:
    """ HTTP POST change SSB address
    """
    current_ssb_address = get_ssb_address(actor_json)
    if fields.get('ssbAddress'):
        if fields['ssbAddress'] != current_ssb_address:
            set_ssb_address(actor_json,
                            fields['ssbAddress'])
            actor_changed = True
    else:
        if current_ssb_address:
            set_ssb_address(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_matrix_address(actor_json: {}, fields: {},
                                 actor_changed: bool) -> bool:
    """ HTTP POST change matrix address
    """
    current_matrix_address = get_matrix_address(actor_json)
    if fields.get('matrixAddress'):
        if fields['matrixAddress'] != current_matrix_address:
            set_matrix_address(actor_json,
                               fields['matrixAddress'])
            actor_changed = True
    else:
        if current_matrix_address:
            set_matrix_address(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_xmpp_address(actor_json: {}, fields: {},
                               actor_changed: bool) -> bool:
    """ HTTP POST change xmpp address
    """
    current_xmpp_address = get_xmpp_address(actor_json)
    if fields.get('xmppAddress'):
        if fields['xmppAddress'] != current_xmpp_address:
            set_xmpp_address(actor_json,
                             fields['xmppAddress'])
            actor_changed = True
    else:
        if current_xmpp_address:
            set_xmpp_address(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_youtube(actor_json: {}, fields: {},
                          actor_changed: bool) -> bool:
    """ HTTP POST change youtube channel address
    """
    current_youtube = get_youtube(actor_json)
    if fields.get('youtubeChannel'):
        if fields['youtubeChannel'] != current_youtube:
            set_youtube(actor_json,
                        fields['youtubeChannel'])
            actor_changed = True
    else:
        if current_youtube:
            set_youtube(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_music_site_url(actor_json: {}, fields: {},
                                 actor_changed: bool) -> bool:
    """ HTTP POST change music site url address
    """
    current_music = get_music_site_url(actor_json)
    if fields.get('musicSiteUrl'):
        if fields['musicSiteUrl'] != current_music:
            set_music_site_url(actor_json,
                               fields['musicSiteUrl'])
            actor_changed = True
    else:
        if current_music:
            set_music_site_url(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_art_site_url(actor_json: {}, fields: {},
                               actor_changed: bool) -> bool:
    """ HTTP POST change art site url address
    """
    current_art = get_art_site_url(actor_json)
    if fields.get('artSiteUrl'):
        if fields['artSiteUrl'] != current_art:
            set_art_site_url(actor_json,
                             fields['artSiteUrl'])
            actor_changed = True
    else:
        if current_art:
            set_art_site_url(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_discord(actor_json: {}, fields: {},
                          actor_changed: bool) -> bool:
    """ HTTP POST change discord channel address
    """
    current_discord = get_discord(actor_json)
    if fields.get('discordChannel'):
        if fields['discordChannel'] != current_discord:
            set_discord(actor_json,
                        fields['discordChannel'])
            actor_changed = True
    else:
        if current_discord:
            set_discord(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_pixelfed(actor_json: {}, fields: {},
                           actor_changed: bool) -> bool:
    """ HTTP POST change pixelfed channel address
    """
    current_pixelfed = get_pixelfed(actor_json)
    if fields.get('pixelfedChannel'):
        if fields['pixelfedChannel'] != current_pixelfed:
            set_pixelfed(actor_json,
                         fields['pixelfedChannel'])
            actor_changed = True
    else:
        if current_pixelfed:
            set_pixelfed(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_peertube(actor_json: {}, fields: {},
                           actor_changed: bool) -> bool:
    """ HTTP POST change peertube channel address
    """
    current_peertube = get_peertube(actor_json)
    if fields.get('peertubeChannel'):
        if fields['peertubeChannel'] != current_peertube:
            set_peertube(actor_json,
                         fields['peertubeChannel'])
            actor_changed = True
    else:
        if current_peertube:
            set_peertube(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_pronouns(actor_json: {}, fields: {},
                           actor_changed: bool) -> bool:
    """ HTTP POST change pronouns
    """
    current_pronouns = get_pronouns(actor_json)
    if fields.get('setPronouns'):
        if fields['setPronouns'] != current_pronouns:
            set_pronouns(actor_json,
                         fields['setPronouns'])
            actor_changed = True
    else:
        if current_pronouns:
            set_pronouns(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_email_address(actor_json: {}, fields: {},
                                actor_changed: bool) -> bool:
    """ HTTP POST change email address
    """
    current_email_address = get_email_address(actor_json)
    if fields.get('email'):
        if fields['email'] != current_email_address:
            set_email_address(actor_json, fields['email'])
            actor_changed = True
    else:
        if current_email_address:
            set_email_address(actor_json, '')
            actor_changed = True
    return actor_changed


def _profile_post_deltachat_invite(actor_json: {}, fields: {},
                                   actor_changed: bool,
                                   translate: {}) -> bool:
    """ HTTP POST change deltachat invite link
    """
    current_deltachat_invite = get_deltachat_invite(actor_json, translate)
    if fields.get('deltachat'):
        if fields['deltachat'] != current_deltachat_invite:
            set_deltachat_invite(actor_json, fields['deltachat'], translate)
            actor_changed = True
    else:
        if current_deltachat_invite:
            set_deltachat_invite(actor_json, '', translate)
            actor_changed = True
    return actor_changed


def _profile_post_memorial_accounts(base_dir: str, domain: str,
                                    person_cache: {}, fields: {}) -> None:
    """ HTTP POST change memorial accounts
    """
    curr_memorial = get_memorials(base_dir)
    if fields.get('memorialAccounts'):
        if fields['memorialAccounts'] != curr_memorial:
            set_memorials(base_dir, domain,
                          fields['memorialAccounts'])
            update_memorial_flags(base_dir,
                                  person_cache)
    else:
        if curr_memorial:
            set_memorials(base_dir, domain, '')
            update_memorial_flags(base_dir, person_cache)


def _profile_post_instance_desc(self, base_dir: str, fields: {}) -> None:
    """ HTTP POST change instance description
    """
    curr_instance_description = \
        get_config_param(base_dir, 'instanceDescription')
    if fields.get('instanceDescription'):
        if fields['instanceDescription'] != curr_instance_description:
            set_config_param(base_dir,
                             'instanceDescription',
                             fields['instanceDescription'])
            self.server.instance_description = \
                fields['instanceDescription']
    else:
        if curr_instance_description:
            set_config_param(base_dir,
                             'instanceDescription', '')
            self.server.instance_description = ''


def _profile_post_instance_short_desc(self, base_dir: str, fields: {}) -> None:
    """ HTTP POST change instance short description
    """
    curr_instance_description_short = \
        get_config_param(base_dir, 'instanceDescriptionShort')
    if fields.get('instanceDescriptionShort'):
        if fields['instanceDescriptionShort'] != \
           curr_instance_description_short:
            idesc = fields['instanceDescriptionShort']
            set_config_param(base_dir,
                             'instanceDescriptionShort', idesc)
            self.server.instance_description_short = idesc
    else:
        if curr_instance_description_short:
            set_config_param(base_dir,
                             'instanceDescriptionShort', '')
            self.server.instance_description_short = 'Epicyon'


def _profile_post_content_license(base_dir: str, fields: {}, self) -> None:
    """ HTTP POST change instance content license
    """
    if fields.get('contentLicenseUrl'):
        if fields['contentLicenseUrl'] != self.server.content_license_url:
            license_str = fields['contentLicenseUrl']
            if '://' not in license_str:
                license_str = license_link_from_name(license_str)
            set_config_param(base_dir,
                             'contentLicenseUrl',
                             license_str)
            self.server.content_license_url = license_str
    else:
        license_str = 'https://creativecommons.org/licenses/by-nc/4.0'
        set_config_param(base_dir, 'contentLicenseUrl', license_str)
        self.server.content_license_url = license_str


def _profile_post_libretranslate_api_key(base_dir: str, fields: {}) -> None:
    """ HTTP POST libretranslate API Key
    """
    curr_libretranslate_api_key = \
        get_config_param(base_dir, 'libretranslateApiKey')
    if fields.get('libretranslateApiKey'):
        if fields['libretranslateApiKey'] != curr_libretranslate_api_key:
            lt_api_key = fields['libretranslateApiKey']
            set_config_param(base_dir,
                             'libretranslateApiKey',
                             lt_api_key)
    else:
        if curr_libretranslate_api_key:
            set_config_param(base_dir,
                             'libretranslateApiKey', '')


def _profile_post_registrations_remaining(base_dir: str, fields: {}) -> None:
    """ HTTP POST change registrations remaining
    """
    reg_str = "registrationsRemaining"
    remaining = get_config_param(base_dir, reg_str)
    if fields.get('regRemaining'):
        if fields['regRemaining'] != remaining:
            remaining = int(fields['regRemaining'])
            if remaining < 0:
                remaining = 0
            elif remaining > 10:
                remaining = 10
            set_config_param(base_dir, reg_str,
                             remaining)


def _profile_post_libretranslate_url(base_dir: str, fields: {}) -> None:
    """ HTTP POST libretranslate URL
    """
    curr_libretranslate_url = get_config_param(base_dir, 'libretranslateUrl')
    if fields.get('libretranslateUrl'):
        if fields['libretranslateUrl'] != curr_libretranslate_url:
            lt_url = fields['libretranslateUrl']
            if resembles_url(lt_url):
                set_config_param(base_dir, 'libretranslateUrl', lt_url)
    else:
        if curr_libretranslate_url:
            set_config_param(base_dir,
                             'libretranslateUrl', '')


def _profile_post_replies_unlisted(base_dir: str, fields: {}, self) -> None:
    """ HTTP POST change public replies unlisted
    """
    pub_replies_unlisted = False
    if self.server.public_replies_unlisted or \
       get_config_param(base_dir, "publicRepliesUnlisted") is True:
        pub_replies_unlisted = True
    if fields.get('publicRepliesUnlisted'):
        if fields['publicRepliesUnlisted'] != pub_replies_unlisted:
            pub_replies_unlisted = fields['publicRepliesUnlisted']
            set_config_param(base_dir,
                             'publicRepliesUnlisted',
                             True)
            self.server.public_replies_unlisted = pub_replies_unlisted
    else:
        if pub_replies_unlisted:
            set_config_param(base_dir,
                             'publicRepliesUnlisted',
                             False)
            self.server.public_replies_unlisted = False


def _profile_post_registrations_open(base_dir: str, fields: {}, self) -> None:
    """ HTTP POST change registrations open status
    """
    registrations_open = False
    if self.server.registration or \
       get_config_param(base_dir, "registration") == 'open':
        registrations_open = True
    if fields.get('regOpen'):
        if fields['regOpen'] != registrations_open:
            registrations_open = fields['regOpen']
            set_config_param(base_dir, 'registration',
                             'open')
            remaining = \
                get_config_param(base_dir, 'registrationsRemaining')
            if not remaining:
                set_config_param(base_dir,
                                 'registrationsRemaining',
                                 10)
            self.server.registration = True
    else:
        if registrations_open:
            set_config_param(base_dir, 'registration',
                             'closed')
            self.server.registration = False


def _profile_post_submit_button(base_dir: str, fields: {}) -> None:
    """ HTTP POST change custom post submit button text
    """
    curr_custom_submit_text = get_config_param(base_dir, 'customSubmitText')
    if fields.get('customSubmitText'):
        if fields['customSubmitText'] != curr_custom_submit_text:
            custom_text = fields['customSubmitText']
            set_config_param(base_dir, 'customSubmitText', custom_text)
    else:
        if curr_custom_submit_text:
            set_config_param(base_dir, 'customSubmitText', '')


def _profile_post_twitter_alt_domain(base_dir: str, fields: {},
                                     self) -> None:
    """ HTTP POST change twitter alternate domain, such as nitter.net
    """
    if fields.get('twitterdomain'):
        curr_twitter_domain = self.server.twitter_replacement_domain
        if fields['twitterdomain'] != curr_twitter_domain:
            new_twitter_domain = fields['twitterdomain']
            if '://' in new_twitter_domain:
                new_twitter_domain = new_twitter_domain.split('://')[1]
            if '/' in new_twitter_domain:
                new_twitter_domain = new_twitter_domain.split('/')[0]
            if '.' in new_twitter_domain:
                set_config_param(base_dir, 'twitterdomain',
                                 new_twitter_domain)
                self.server.twitter_replacement_domain = new_twitter_domain
    else:
        set_config_param(base_dir, 'twitterdomain', '')
        self.server.twitter_replacement_domain = None


def _profile_post_youtube_alt_domain(base_dir: str, fields: {},
                                     self) -> None:
    """ HTTP POST change YouTube alternate domain
    """
    if fields.get('ytdomain'):
        curr_yt_domain = self.server.yt_replace_domain
        if fields['ytdomain'] != curr_yt_domain:
            new_yt_domain = fields['ytdomain']
            if '://' in new_yt_domain:
                new_yt_domain = new_yt_domain.split('://')[1]
            if '/' in new_yt_domain:
                new_yt_domain = new_yt_domain.split('/')[0]
            if '.' in new_yt_domain:
                set_config_param(base_dir, 'youtubedomain',
                                 new_yt_domain)
                self.server.yt_replace_domain = new_yt_domain
    else:
        set_config_param(base_dir, 'youtubedomain', '')
        self.server.yt_replace_domain = None


def _profile_post_instance_title(base_dir: str, fields: {}) -> None:
    """ HTTP POST change instance title
    """
    if fields.get('instanceTitle'):
        curr_instance_title = get_config_param(base_dir, 'instanceTitle')
        if fields['instanceTitle'] != curr_instance_title:
            set_config_param(base_dir, 'instanceTitle',
                             fields['instanceTitle'])


def _profile_post_blog_instance_status(base_dir: str, fields: {},
                                       self) -> None:
    """ HTTP POST blog instance status
    """
    if fields.get('blogsInstance'):
        self.server.blogs_instance = False
        self.server.default_timeline = 'inbox'
        if fields['blogsInstance'] == 'on':
            self.server.blogs_instance = True
            self.server.media_instance = False
            self.server.news_instance = False
            self.server.default_timeline = 'tlblogs'
        set_config_param(base_dir, "blogsInstance",
                         self.server.blogs_instance)
        set_config_param(base_dir, "mediaInstance",
                         self.server.media_instance)
        set_config_param(base_dir, "newsInstance",
                         self.server.news_instance)
    else:
        if self.server.blogs_instance:
            self.server.blogs_instance = False
            self.server.default_timeline = 'inbox'
            set_config_param(base_dir, "blogsInstance",
                             self.server.blogs_instance)


def _profile_post_news_instance_status(base_dir: str, fields: {},
                                       self) -> None:
    """ HTTP POST change news instance status
    """
    if fields.get('newsInstance'):
        self.server.news_instance = False
        self.server.default_timeline = 'inbox'
        if fields['newsInstance'] == 'on':
            self.server.news_instance = True
            self.server.blogs_instance = False
            self.server.media_instance = False
            self.server.default_timeline = 'tlfeatures'
        set_config_param(base_dir, "mediaInstance",
                         self.server.media_instance)
        set_config_param(base_dir, "blogsInstance",
                         self.server.blogs_instance)
        set_config_param(base_dir, "newsInstance",
                         self.server.news_instance)
    else:
        if self.server.news_instance:
            self.server.news_instance = False
            self.server.default_timeline = 'inbox'
            set_config_param(base_dir, "newsInstance",
                             self.server.media_instance)


def _profile_post_media_instance_status(base_dir: str, fields: {},
                                        self) -> None:
    """ HTTP POST change media instance status
    """
    if fields.get('mediaInstance'):
        self.server.media_instance = False
        self.server.default_timeline = 'inbox'
        if fields['mediaInstance'] == 'on':
            self.server.media_instance = True
            self.server.blogs_instance = False
            self.server.news_instance = False
            self.server.default_timeline = 'tlmedia'
        set_config_param(base_dir, "mediaInstance",
                         self.server.media_instance)
        set_config_param(base_dir, "blogsInstance",
                         self.server.blogs_instance)
        set_config_param(base_dir, "newsInstance",
                         self.server.news_instance)
    else:
        if self.server.media_instance:
            self.server.media_instance = False
            self.server.default_timeline = 'inbox'
            set_config_param(base_dir, "mediaInstance",
                             self.server.media_instance)


def _profile_post_theme_change(base_dir: str, nickname: str,
                               domain: str, domain_full: str,
                               admin_nickname: str, fields: {},
                               theme_name: str, http_prefix: str,
                               allow_local_network_access: bool,
                               system_language: str,
                               dyslexic_font: bool, self) -> None:
    """ HTTP POST change the theme from edit profile screen
    """
    if nickname == admin_nickname or is_artist(base_dir, nickname):
        if fields.get('themeDropdown'):
            if theme_name != fields['themeDropdown']:
                theme_name = fields['themeDropdown']
                set_theme(base_dir, theme_name,
                          domain, allow_local_network_access,
                          system_language,
                          dyslexic_font, True)
                self.server.text_mode_banner = get_text_mode_banner(base_dir)
                self.server.iconsCache = {}
                self.server.fontsCache = {}
                self.server.css_cache = {}
                self.server.show_publish_as_icon = \
                    get_config_param(base_dir, 'showPublishAsIcon')
                self.server.full_width_tl_button_header = \
                    get_config_param(base_dir, 'fullWidthTlButtonHeader')
                self.server.icons_as_buttons = \
                    get_config_param(base_dir, 'iconsAsButtons')
                self.server.rss_icon_at_top = \
                    get_config_param(base_dir, 'rssIconAtTop')
                self.server.publish_button_at_top = \
                    get_config_param(base_dir, 'publishButtonAtTop')
                set_news_avatar(base_dir, fields['themeDropdown'],
                                http_prefix, domain, domain_full)


def _profile_post_change_displayed_name(base_dir: str,
                                        nickname: str, domain: str,
                                        system_language: str,
                                        actor_json: {},
                                        fields: {},
                                        check_name_and_bio: bool,
                                        actor_changed: bool,
                                        redirect_path: str) -> (bool, str):
    """ HTTP POST change displayed name
    """
    if fields.get('displayNickname'):
        if fields['displayNickname'] != actor_json['name']:
            display_name = remove_html(fields['displayNickname'])
            if not is_filtered(base_dir, nickname, domain,
                               display_name, system_language):
                actor_json['name'] = display_name
            else:
                actor_json['name'] = nickname
                if check_name_and_bio:
                    redirect_path = '/welcome_profile'
            actor_changed = True
    else:
        if check_name_and_bio:
            redirect_path = '/welcome_profile'
    return actor_changed, redirect_path


def _profile_post_change_city(base_dir: str, nickname: str, domain: str,
                              fields: {}) -> None:
    """ HTTP POST change city
    """
    if fields.get('cityDropdown'):
        city_filename = acct_dir(base_dir, nickname, domain) + '/city.txt'
        try:
            with open(city_filename, 'w+',
                      encoding='utf-8') as fp_city:
                fp_city.write(fields['cityDropdown'])
        except OSError:
            print('EX: edit profile unable to write city ' + city_filename)


def _profile_post_set_reply_interval(base_dir: str, nickname: str, domain: str,
                                     fields: {}) -> None:
    """ HTTP POST reply interval in hours
    """
    if fields.get('replyhours'):
        if fields['replyhours'].isdigit():
            set_reply_interval_hours(base_dir,
                                     nickname, domain,
                                     fields['replyhours'])


def _profile_post_change_password(base_dir: str, nickname: str,
                                  fields: {}, debug: bool) -> None:
    """ HTTP POST change password
    """
    if fields.get('password') and fields.get('passwordconfirm'):
        fields['password'] = remove_eol(fields['password']).strip()
        fields['passwordconfirm'] = \
            remove_eol(fields['passwordconfirm']).strip()
        if valid_password(fields['password'], debug) and \
           fields['password'] == fields['passwordconfirm']:
            # set password
            store_basic_credentials(base_dir, nickname,
                                    fields['password'])


def _profile_post_skill_level(actor_json: {},
                              fields: {},
                              base_dir: str, nickname: str, domain: str,
                              system_language: str,
                              translate: {},
                              actor_changed: bool) -> bool:
    """ HTTP POST set skill levels
    """
    skill_ctr = 1
    actor_skills_ctr = no_of_actor_skills(actor_json)
    while skill_ctr < 10:
        skill_name = fields.get('skillName' + str(skill_ctr))
        if not skill_name:
            skill_ctr += 1
            continue
        if is_filtered(base_dir, nickname, domain, skill_name,
                       system_language):
            skill_ctr += 1
            continue
        skill_value = fields.get('skillValue' + str(skill_ctr))
        if not skill_value:
            skill_ctr += 1
            continue
        if not actor_has_skill(actor_json, skill_name):
            actor_changed = True
        else:
            if actor_skill_value(actor_json, skill_name) != \
               int(skill_value):
                actor_changed = True
        set_actor_skill_level(actor_json,
                              skill_name, int(skill_value))
        skills_str = translate['Skills']
        skills_str = skills_str.lower()
        set_hashtag_category(base_dir, skill_name,
                             skills_str, False, False)
        skill_ctr += 1
    if no_of_actor_skills(actor_json) != actor_skills_ctr:
        actor_changed = True
    return actor_changed


def _profile_post_avatar_image_ext(profile_media_types_uploaded: {},
                                   actor_json: {}) -> None:
    """ HTTP POST update the avatar/image url file extension
    """
    uploads = profile_media_types_uploaded.items()
    for m_type, last_part in uploads:
        rep_str = '/' + last_part
        if m_type == 'avatar':
            url_str = get_url_from_post(actor_json['icon']['url'])
            actor_url = remove_html(url_str)
            last_part_of_url = actor_url.split('/')[-1]
            srch_str = '/' + last_part_of_url
            actor_url = actor_url.replace(srch_str, rep_str)
            actor_json['icon']['url'] = actor_url
            print('actor_url: ' + actor_url)
            if '.' in actor_url:
                img_ext = actor_url.split('.')[-1]
                if img_ext == 'jpg':
                    img_ext = 'jpeg'
                actor_json['icon']['mediaType'] = 'image/' + img_ext
        elif m_type == 'image':
            url_str = get_url_from_post(actor_json['image']['url'])
            im_url = remove_html(url_str)
            last_part_of_url = im_url.split('/')[-1]
            srch_str = '/' + last_part_of_url
            actor_json['image']['url'] = im_url.replace(srch_str, rep_str)
            if '.' in im_url:
                img_ext = im_url.split('.')[-1]
                if img_ext == 'jpg':
                    img_ext = 'jpeg'
                actor_json['image']['mediaType'] = 'image/' + img_ext


def profile_edit(self, calling_domain: str, cookie: str,
                 path: str, base_dir: str, http_prefix: str,
                 domain: str, domain_full: str,
                 onion_domain: str, i2p_domain: str,
                 debug: bool, allow_local_network_access: bool,
                 system_language: str,
                 content_license_url: str,
                 curr_session, proxy_type: str,
                 cached_webfingers: {},
                 person_cache: {}, project_version: str,
                 translate: {}, theme_name: str,
                 dyslexic_font: bool,
                 peertube_instances: [],
                 mitm_servers: []) -> None:
    """Updates your user profile after editing via the Edit button
    on the profile screen
    """
    users_path = path.replace('/profiledata', '')
    users_path = users_path.replace('/editprofile', '')
    actor_str = \
        get_instance_url(calling_domain, http_prefix, domain_full,
                         onion_domain, i2p_domain) + \
        users_path

    boundary = None
    if ' boundary=' in self.headers['Content-type']:
        boundary = self.headers['Content-type'].split('boundary=')[1]
        if ';' in boundary:
            boundary = boundary.split(';')[0]

    # get the nickname
    nickname = get_nickname_from_actor(actor_str)
    if not nickname:
        print('WARN: nickname not found in ' + actor_str)
        redirect_headers(self, actor_str, cookie, calling_domain, 303)
        self.server.postreq_busy = False
        return

    if self.headers.get('Content-length'):
        length = int(self.headers['Content-length'])

        # check that the POST isn't too large
        if length > self.server.max_post_length:
            print('Maximum profile data length exceeded ' +
                  str(length))
            redirect_headers(self, actor_str, cookie, calling_domain, 303)
            self.server.postreq_busy = False
            return

    try:
        # read the bytes of the http form POST
        post_bytes = self.rfile.read(length)
    except SocketError as ex:
        if ex.errno == errno.ECONNRESET:
            print('EX: connection was reset while ' +
                  'reading bytes from http form POST')
        else:
            print('EX: error while reading bytes ' +
                  'from http form POST')
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return
    except ValueError as ex:
        print('EX: failed to read bytes for POST, ' + str(ex))
        self.send_response(400)
        self.end_headers()
        self.server.postreq_busy = False
        return

    admin_nickname = get_config_param(base_dir, 'admin')

    if not boundary:
        if b'--LYNX' in post_bytes:
            boundary = '--LYNX'

    if debug:
        print('post_bytes: ' + str(post_bytes))

    if boundary:
        # get the various avatar, banner and background images
        actor_changed = True
        send_move_activity = False
        profile_media_types = (
            'avatar', 'image',
            'banner', 'search_banner',
            'instanceLogo',
            'left_col_image', 'right_col_image',
            'watermark_image',
            'importFollows',
            'importTheme'
        )
        profile_media_types_uploaded = {}
        for m_type in profile_media_types:
            # some images can only be changed by the admin
            if m_type == 'instanceLogo':
                if nickname != admin_nickname:
                    print('WARN: only the admin can change ' +
                          'instance logo')
                    continue

            if debug:
                print('DEBUG: profile update extracting ' + m_type +
                      ' image, zip, csv or font from POST')
            media_bytes, post_bytes = \
                extract_media_in_form_post(post_bytes, boundary, m_type)
            if media_bytes:
                if debug:
                    print('DEBUG: profile update ' + m_type +
                          ' image, zip, csv or font was found. ' +
                          str(len(media_bytes)) + ' bytes')
            else:
                if debug:
                    print('DEBUG: profile update, no ' + m_type +
                          ' image, zip, csv or font was found in POST')
                continue

            # Note: a .temp extension is used here so that at no
            # time is an image with metadata publicly exposed,
            # even for a few mS
            if m_type == 'instanceLogo':
                filename_base = data_dir(base_dir) + '/login.temp'
            elif m_type == 'importTheme':
                if not os.path.isdir(base_dir + '/imports'):
                    os.mkdir(base_dir + '/imports')
                filename_base = base_dir + '/imports/newtheme.zip'
                if os.path.isfile(filename_base):
                    try:
                        os.remove(filename_base)
                    except OSError:
                        print('EX: _profile_edit unable to delete ' +
                              filename_base)
            elif m_type == 'importFollows':
                filename_base = \
                    acct_dir(base_dir, nickname, domain) + \
                    '/import_following.csv'
            else:
                filename_base = \
                    acct_dir(base_dir, nickname, domain) + \
                    '/' + m_type + '.temp'

            filename, _ = \
                save_media_in_form_post(media_bytes, debug,
                                        filename_base)
            if filename:
                print('Profile update POST ' + m_type +
                      ' media, zip, csv or font filename is ' + filename)
            else:
                print('Profile update, no ' + m_type +
                      ' media, zip, csv or font filename in POST')
                continue

            if m_type == 'importFollows':
                if os.path.isfile(filename_base):
                    print(nickname + ' imported follows csv')
                else:
                    print('WARN: failed to import follows from csv for ' +
                          nickname)
                continue

            if m_type == 'importTheme':
                if nickname == admin_nickname or \
                   is_artist(base_dir, nickname):
                    if import_theme(base_dir, filename):
                        print(nickname + ' uploaded a theme')
                else:
                    print('Only admin or artist can import a theme')
                continue

            post_image_filename = filename.replace('.temp', '')
            if debug:
                print('DEBUG: POST ' + m_type +
                      ' media removing metadata')
            # remove existing etag
            if os.path.isfile(post_image_filename + '.etag'):
                try:
                    os.remove(post_image_filename + '.etag')
                except OSError:
                    print('EX: _profile_edit unable to delete ' +
                          post_image_filename + '.etag')

            city = get_spoofed_city(self.server.city,
                                    base_dir, nickname, domain)

            if self.server.low_bandwidth:
                convert_image_to_low_bandwidth(filename)
            process_meta_data(base_dir, nickname, domain,
                              filename, post_image_filename, city,
                              content_license_url)
            if os.path.isfile(post_image_filename):
                print('profile update POST ' + m_type +
                      ' image, zip or font saved to ' +
                      post_image_filename)
                if m_type != 'instanceLogo':
                    last_part_of_image_filename = \
                        post_image_filename.split('/')[-1]
                    profile_media_types_uploaded[m_type] = \
                        last_part_of_image_filename
                    actor_changed = True
            else:
                print('ERROR: profile update POST ' + m_type +
                      ' image or font could not be saved to ' +
                      post_image_filename)

        post_bytes_str = post_bytes.decode('utf-8')
        redirect_path = ''
        check_name_and_bio = False
        on_final_welcome_screen = False
        if 'name="previewAvatar"' in post_bytes_str:
            redirect_path = '/welcome_profile'
        elif 'name="initialWelcomeScreen"' in post_bytes_str:
            redirect_path = '/welcome'
        elif 'name="finalWelcomeScreen"' in post_bytes_str:
            check_name_and_bio = True
            redirect_path = '/welcome_final'
        elif 'name="welcomeCompleteButton"' in post_bytes_str:
            redirect_path = '/' + self.server.default_timeline
            welcome_screen_is_complete(base_dir, nickname,
                                       domain)
            on_final_welcome_screen = True
        elif 'name="submitExportTheme"' in post_bytes_str:
            print('submitExportTheme')
            theme_download_path = actor_str
            if export_theme(base_dir, theme_name):
                theme_download_path += '/exports/' + theme_name + '.zip'
            print('submitExportTheme path=' + theme_download_path)
            redirect_headers(self, theme_download_path,
                             cookie, calling_domain, 303)
            self.server.postreq_busy = False
            return
        elif 'name="submitExportBlocks"' in post_bytes_str:
            print('submitExportBlocks')
            blocks_download_path = actor_str + '/exports/blocks.csv'
            print('submitExportBlocks path=' + blocks_download_path)
            redirect_headers(self, blocks_download_path,
                             cookie, calling_domain, 303)
            self.server.postreq_busy = False
            return

        # extract all of the text fields into a dict
        fields = \
            extract_text_fields_in_post(post_bytes, boundary, debug, None)
        if debug:
            if fields:
                print('DEBUG: profile update text ' +
                      'field extracted from POST ' + str(fields))
            else:
                print('WARN: profile update, no text ' +
                      'fields could be extracted from POST')

        # load the json for the actor for this user
        actor_filename = acct_dir(base_dir, nickname, domain) + '.json'
        if os.path.isfile(actor_filename):
            actor_json = load_json(actor_filename)
            if actor_json:
                if not actor_json.get('discoverable'):
                    # discoverable in profile directory
                    # which isn't implemented in Epicyon
                    actor_json['discoverable'] = True
                    actor_changed = True
                if actor_json.get('capabilityAcquisitionEndpoint'):
                    del actor_json['capabilityAcquisitionEndpoint']
                    actor_changed = True

                _profile_post_avatar_image_ext(profile_media_types_uploaded,
                                               actor_json)

                actor_changed = \
                    _profile_post_skill_level(actor_json,
                                              fields,
                                              base_dir, nickname, domain,
                                              system_language,
                                              translate,
                                              actor_changed)

                _profile_post_change_password(base_dir, nickname, fields,
                                              debug)

                _profile_post_set_reply_interval(base_dir, nickname, domain,
                                                 fields)
                _profile_post_change_city(base_dir, nickname, domain,
                                          fields)
                actor_changed, redirect_path = \
                    _profile_post_change_displayed_name(base_dir,
                                                        nickname, domain,
                                                        system_language,
                                                        actor_json,
                                                        fields,
                                                        check_name_and_bio,
                                                        actor_changed,
                                                        redirect_path)
                _profile_post_theme_change(base_dir, nickname,
                                           domain, domain_full,
                                           admin_nickname, fields,
                                           theme_name,
                                           http_prefix,
                                           allow_local_network_access,
                                           system_language,
                                           dyslexic_font, self)

                # is this the admin profile?
                if nickname == admin_nickname:
                    _profile_post_media_instance_status(base_dir, fields, self)

                    # is this a news theme?
                    if is_news_theme_name(base_dir, theme_name):
                        fields['newsInstance'] = 'on'

                    _profile_post_news_instance_status(base_dir, fields, self)

                    _profile_post_blog_instance_status(base_dir, fields, self)

                    _profile_post_instance_title(base_dir, fields)

                    _profile_post_youtube_alt_domain(base_dir, fields,
                                                     self)

                    _profile_post_twitter_alt_domain(base_dir, fields, self)

                    _profile_post_submit_button(base_dir, fields)

                    _profile_post_registrations_open(base_dir, fields, self)

                    _profile_post_replies_unlisted(base_dir, fields, self)

                    _profile_post_registrations_remaining(base_dir, fields)

                    _profile_post_libretranslate_url(base_dir, fields)

                    _profile_post_libretranslate_api_key(base_dir, fields)

                    _profile_post_content_license(base_dir, fields, self)

                    _profile_post_instance_short_desc(self, base_dir, fields)

                    _profile_post_instance_desc(self, base_dir, fields)

                    _profile_post_memorial_accounts(base_dir, domain,
                                                    person_cache, fields)
                actor_changed = \
                    _profile_post_email_address(actor_json, fields,
                                                actor_changed)

                actor_changed = \
                    _profile_post_deltachat_invite(actor_json, fields,
                                                   actor_changed, translate)

                actor_changed = \
                    _profile_post_xmpp_address(actor_json, fields,
                                               actor_changed)

                actor_changed = \
                    _profile_post_pixelfed(actor_json, fields,
                                           actor_changed)

                actor_changed = \
                    _profile_post_discord(actor_json, fields,
                                          actor_changed)

                actor_changed = \
                    _profile_post_youtube(actor_json, fields,
                                          actor_changed)

                actor_changed = \
                    _profile_post_music_site_url(actor_json, fields,
                                                 actor_changed)

                actor_changed = \
                    _profile_post_art_site_url(actor_json, fields,
                                               actor_changed)

                actor_changed = \
                    _profile_post_peertube(actor_json, fields,
                                           actor_changed)

                actor_changed = \
                    _profile_post_pronouns(actor_json, fields,
                                           actor_changed)

                actor_changed = \
                    _profile_post_matrix_address(actor_json, fields,
                                                 actor_changed)

                actor_changed = \
                    _profile_post_ssb_address(actor_json, fields,
                                              actor_changed)

                actor_changed = \
                    _profile_post_blog_address(curr_session,
                                               base_dir,
                                               http_prefix,
                                               nickname, domain,
                                               actor_json, fields,
                                               actor_changed,
                                               debug, mitm_servers)

                actor_changed = \
                    _profile_post_show_languages(actor_json, fields,
                                                 actor_changed)

                actor_changed = \
                    _profile_post_time_zone(base_dir, nickname, domain,
                                            fields, actor_changed, self)

                actor_changed = \
                    _profile_post_expiry(base_dir, nickname, domain,
                                         fields, actor_changed)

                _profile_post_max_preview(base_dir, nickname, domain, fields)

                actor_changed = \
                    _profile_post_birthday(fields, actor_json, actor_changed)

                actor_changed = \
                    _profile_post_tox_address(fields, actor_json,
                                              actor_changed)

                actor_changed = \
                    _profile_post_briar_address(fields, actor_json,
                                                actor_changed)

                actor_changed = \
                    _profile_post_cwtch_address(fields, actor_json,
                                                actor_changed)

                _profile_post_ntfy_url(base_dir, nickname, domain, fields)

                _profile_post_ntfy_topic(base_dir, nickname, domain, fields)

                actor_changed = \
                    _profile_post_enigma_pubkey(actor_json, fields,
                                                actor_changed)

                actor_changed = \
                    _profile_post_pgp_pubkey(actor_json, fields,
                                             actor_changed)

                actor_changed = \
                    _profile_post_pgp_fingerprint(actor_json, fields,
                                                  actor_changed)

                actor_changed = \
                    _profile_post_donation_link(actor_json, fields,
                                                actor_changed)

                actor_changed = \
                    _profile_post_website(curr_session,
                                          base_dir,
                                          http_prefix,
                                          nickname, domain,
                                          actor_json, fields,
                                          actor_changed,
                                          translate,
                                          debug, mitm_servers)

                actor_changed = \
                    _profile_post_gemini_link(actor_json, fields,
                                              actor_changed)

                actor_changed, send_move_activity = \
                    _profile_post_moved(actor_json, fields,
                                        actor_changed,
                                        send_move_activity)

                actor_changed = \
                    _profile_post_occupation(actor_json, fields,
                                             actor_changed)

                actor_changed = \
                    _profile_post_featured_hashtags(actor_json, fields,
                                                    actor_changed)

                actor_changed = \
                    _profile_post_alsoknownas(actor_json, fields,
                                              actor_changed)

                actor_changed, redirect_path = \
                    _profile_post_bio(actor_json, fields,
                                      base_dir, http_prefix,
                                      nickname, domain, domain_full,
                                      system_language, translate,
                                      actor_changed,
                                      redirect_path,
                                      check_name_and_bio)

                admin_nickname = \
                    get_config_param(base_dir, 'admin')

                if admin_nickname:
                    # whether to require jsonld signatures
                    # on all incoming posts
                    if path.startswith('/users/' +
                                       admin_nickname + '/'):
                        _profile_post_show_nodeinfo(base_dir, fields, self)

                        _profile_post_show_nodeinfo_version(base_dir, fields,
                                                            self)
                        _profile_post_verify_all_signatures(base_dir, fields,
                                                            self)

                        _profile_post_broch_mode(base_dir, domain_full, fields)

                        _profile_post_shared_item_federation_domains(base_dir,
                                                                     fields,
                                                                     self)
                    # change moderators list
                    set_roles_from_list(base_dir, domain, admin_nickname,
                                        'moderators', 'moderator', fields,
                                        path, 'moderators.txt')

                    # change site editors list
                    set_roles_from_list(base_dir, domain, admin_nickname,
                                        'editors', 'editor', fields,
                                        path, 'editors.txt')

                    # change site devops list
                    set_roles_from_list(base_dir, domain, admin_nickname,
                                        'devopslist', 'devops', fields,
                                        path, 'devops.txt')

                    # change site counselors list
                    set_roles_from_list(base_dir, domain, admin_nickname,
                                        'counselors', 'counselor', fields,
                                        path, 'counselors.txt')

                    # change site artists list
                    set_roles_from_list(base_dir, domain, admin_nickname,
                                        'artists', 'artist', fields,
                                        path, 'artists.txt')

                # remove scheduled posts
                if fields.get('removeScheduledPosts'):
                    if fields['removeScheduledPosts'] == 'on':
                        remove_scheduled_posts(base_dir, nickname, domain)

                premium = is_premium_account(base_dir, nickname, domain)
                actor_changed = \
                    _profile_post_approve_followers(on_final_welcome_screen,
                                                    actor_json, fields,
                                                    actor_changed, premium,
                                                    base_dir, nickname, domain)

                _profile_post_reject_spam_actors(base_dir,
                                                 nickname, domain, fields)

                actor_changed = \
                    _profile_post_keep_dms(base_dir,
                                           nickname, domain,
                                           fields, actor_changed)

                _profile_post_remove_custom_font(base_dir, nickname, domain,
                                                 system_language,
                                                 admin_nickname,
                                                 dyslexic_font,
                                                 path, fields, self)

                actor_changed = \
                    _profile_post_dms_from_followers(base_dir,
                                                     nickname, domain,
                                                     on_final_welcome_screen,
                                                     fields,
                                                     actor_changed)

                _profile_post_remove_retweets(base_dir, nickname, domain,
                                              fields)

                _profile_post_hide_like_button2(base_dir, nickname, domain,
                                                fields)

                min_img_acct = self.server.min_images_for_accounts
                _profile_post_minimize_images(base_dir, nickname, domain,
                                              fields, min_img_acct)

                _profile_post_hide_reaction_button2(base_dir, nickname, domain,
                                                    fields)

                _profile_post_bold_reading(base_dir, nickname, domain,
                                           fields, self)

                _profile_post_reverse_timelines(base_dir,
                                                nickname,
                                                fields, self)

                account_dir = acct_dir(base_dir, nickname, domain)

                _profile_post_show_quote_toots(fields, account_dir)

                _profile_post_show_questions(fields, account_dir)

                _profile_post_only_follower_replies(fields, account_dir)

                _profile_post_mutuals_replies(account_dir, fields)

                actor_changed = \
                    _profile_post_hide_follows(base_dir, nickname, domain,
                                               actor_json, fields, self,
                                               actor_changed, premium)
                actor_changed = \
                    _profile_post_hide_recent_posts(base_dir, nickname, domain,
                                                    actor_json, fields, self,
                                                    actor_changed, premium)
                _profile_post_block_military(nickname, fields, self)
                _profile_post_block_government(nickname, fields, self)
                _profile_post_block_bluesky(nickname, fields, self)
                _profile_post_block_nostr(nickname, fields, self)
                _profile_post_no_reply_boosts(base_dir, nickname, domain,
                                              fields)
                _profile_post_no_seen_posts(base_dir, nickname, domain,
                                            fields)
                _profile_post_watermark_enabled(base_dir, nickname, domain,
                                                fields)

                notify_likes_filename = \
                    acct_dir(base_dir, nickname, domain) + '/.notifyLikes'
                hide_reaction_button_active = False
                if fields.get('hideReactionButton'):
                    if fields['hideReactionButton'] == 'on':
                        hide_reaction_button_active = True
                hide_like_button_active = False
                if fields.get('hideLikeButton'):
                    if fields['hideLikeButton'] == 'on':
                        hide_like_button_active = True

                actor_changed = \
                    _profile_post_notify_likes(on_final_welcome_screen,
                                               notify_likes_filename,
                                               actor_changed, fields,
                                               hide_like_button_active)

                actor_changed = \
                    _profile_post_notify_reactions(base_dir,
                                                   nickname, domain,
                                                   on_final_welcome_screen,
                                                   hide_reaction_button_active,
                                                   fields, actor_changed)
                actor_changed = \
                    _profile_post_account_type(path, actor_json, fields,
                                               admin_nickname, actor_changed)
                _profile_post_grayscale_theme(base_dir, path,
                                              nickname, admin_nickname,
                                              fields)
                _profile_post_dyslexic_font(base_dir, path,
                                            nickname, admin_nickname,
                                            fields, self, theme_name,
                                            domain,
                                            allow_local_network_access,
                                            system_language)
                _profile_post_low_bandwidth(base_dir, path,
                                            nickname, admin_nickname,
                                            fields, self)
                _profile_post_filtered_words(base_dir, nickname, domain,
                                             fields)
                _profile_post_filtered_words_within_bio(base_dir,
                                                        nickname, domain,
                                                        fields)
                _profile_post_word_replacements(base_dir, nickname, domain,
                                                fields)
                _profile_post_autogenerated_tags(base_dir, nickname, domain,
                                                 fields)
                _profile_post_auto_cw(base_dir, nickname, domain,
                                      fields, self)
                # save blocked accounts list
                if fields.get('blocked'):
                    add_account_blocks(base_dir,
                                       nickname, domain,
                                       fields['blocked'])
                else:
                    add_account_blocks(base_dir,
                                       nickname, domain, '')

                _profile_post_import_blocks_csv(base_dir, nickname, domain,
                                                fields)
                _profile_post_import_follows(base_dir, nickname, domain,
                                             fields)
                _profile_post_import_theme(base_dir, nickname,
                                           admin_nickname, fields)
                _profile_post_dm_instances(base_dir, nickname, domain,
                                           fields)
                _profile_post_allowed_instances(base_dir, nickname, domain,
                                                fields)
                if is_moderator(base_dir, nickname):
                    _profile_post_cw_lists(fields, self)
                    _profile_post_blocked_user_agents(base_dir, fields, self)
                    _profile_post_crawlers_allowed(base_dir, fields, self)
                    _profile_post_buy_domains(base_dir, fields, self)
                    _profile_post_block_federated(base_dir, fields, self)
                    _profile_post_robots_txt(base_dir, fields, self)
                    _profile_post_peertube_instances(base_dir, fields, self,
                                                     peertube_instances)

                _profile_post_git_projects(base_dir, nickname, domain,
                                           fields)
                actor_changed = \
                    _profile_post_memorial(base_dir, nickname,
                                           actor_json, actor_changed)

                # save actor json file within accounts
                if actor_changed:
                    _profile_post_save_actor(base_dir, http_prefix,
                                             nickname, domain,
                                             self.server.port,
                                             actor_json, actor_filename,
                                             onion_domain, i2p_domain,
                                             curr_session, proxy_type,
                                             send_move_activity,
                                             self, cached_webfingers,
                                             person_cache, project_version)

                if _profile_post_deactivate_account(base_dir, nickname, domain,
                                                    calling_domain,
                                                    fields, self):
                    return

    # redirect back to the profile screen
    redirect_headers(self, actor_str + redirect_path,
                     cookie, calling_domain, 303)
    self.server.postreq_busy = False
