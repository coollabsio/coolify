__filename__ = "daemon_get_profile.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Daemon GET"

import os
import json
from roles import get_actor_roles_list
from skills import no_of_actor_skills
from skills import get_skills_from_list
from utils import get_nickname_from_actor
from utils import load_json
from utils import get_json_content_from_accept
from utils import get_occupation_skills
from utils import get_instance_url
from utils import acct_dir
from utils import convert_domains
from httpcodes import write2
from httpcodes import http_404
from person import person_lookup
from person import add_alternate_domains
from httprequests import request_http
from httpheaders import redirect_headers
from httpheaders import set_headers
from session import establish_session
from city import get_spoofed_city
from webapp_profile import html_profile
from webapp_profile import html_edit_profile
from fitnessFunctions import fitness_performance
from securemode import secure_mode


def show_person_profile(self, authorized: bool,
                        calling_domain: str,
                        referer_domain: str, path: str,
                        base_dir: str, http_prefix: str,
                        domain: str,
                        onion_domain: str, i2p_domain: str,
                        getreq_start_time,
                        proxy_type: str, cookie: str,
                        debug: str,
                        curr_session,
                        access_keys: {},
                        key_shortcuts: {}, city: str,
                        account_timezone: {},
                        bold_reading_nicknames: {},
                        max_shares_on_profile: int,
                        sites_unavailable: [],
                        fitness: {},
                        signing_priv_key_pem: str,
                        rss_icon_at_top: bool,
                        icons_as_buttons: bool,
                        default_timeline: str,
                        recent_posts_cache: {},
                        max_recent_posts: int,
                        translate: {},
                        project_version: str,
                        cached_webfingers: {},
                        person_cache: {},
                        yt_replace_domain: str,
                        twitter_replacement_domain: str,
                        show_published_date_only: bool,
                        newswire: {},
                        theme_name: str,
                        dormant_months: int,
                        peertube_instances: [],
                        allow_local_network_access: bool,
                        text_mode_banner: str,
                        system_language: str,
                        max_like_count: int,
                        shared_items_federated_domains: [],
                        cw_lists: [],
                        lists_enabled: {},
                        content_license_url: str,
                        buy_sites: [],
                        no_of_books: int,
                        auto_cw_cache: {},
                        mitm_servers: [],
                        hide_recent_posts: {}) -> bool:
    """Shows the profile for a person
    """
    # look up a person
    actor_json = person_lookup(domain, path, base_dir)
    if not actor_json:
        return False
    add_alternate_domains(actor_json, domain, onion_domain, i2p_domain)
    if request_http(self.headers, debug):
        curr_session = \
            establish_session("show_person_profile",
                              curr_session, proxy_type,
                              self.server)
        if not curr_session:
            http_404(self, 86)
            return True

        city = None
        timezone = None
        if '/users/' in path:
            nickname = path.split('/users/')[1]
            if '/' in nickname:
                nickname = nickname.split('/')[0]
            if key_shortcuts.get(nickname):
                access_keys = key_shortcuts[nickname]

            city = get_spoofed_city(city, base_dir, nickname, domain)
            if account_timezone.get(nickname):
                timezone = account_timezone.get(nickname)
        bold_reading = False
        if bold_reading_nicknames.get(nickname):
            bold_reading = True
        known_epicyon_instances = \
            self.server.known_epicyon_instances
        instance_software = \
            self.server.instance_software
        msg = \
            html_profile(signing_priv_key_pem,
                         rss_icon_at_top,
                         icons_as_buttons,
                         default_timeline,
                         recent_posts_cache,
                         max_recent_posts,
                         translate,
                         project_version,
                         base_dir, http_prefix, authorized,
                         actor_json, 'posts', curr_session,
                         cached_webfingers,
                         person_cache,
                         yt_replace_domain,
                         twitter_replacement_domain,
                         show_published_date_only,
                         newswire,
                         theme_name,
                         dormant_months,
                         peertube_instances,
                         allow_local_network_access,
                         text_mode_banner,
                         debug,
                         access_keys, city,
                         system_language,
                         max_like_count,
                         shared_items_federated_domains,
                         None, None, None,
                         cw_lists,
                         lists_enabled,
                         content_license_url,
                         timezone, bold_reading,
                         buy_sites,
                         None,
                         max_shares_on_profile,
                         sites_unavailable,
                         no_of_books,
                         auto_cw_cache,
                         known_epicyon_instances,
                         mitm_servers,
                         instance_software,
                         hide_recent_posts).encode('utf-8')
        msglen = len(msg)
        set_headers(self, 'text/html', msglen,
                    cookie, calling_domain, False)
        write2(self, msg)
        fitness_performance(getreq_start_time, fitness,
                            '_GET', '_show_person_profile',
                            debug)
        if debug:
            print('DEBUG: html actor sent')
    else:
        if secure_mode(curr_session, proxy_type, False,
                       self.server, self.headers, self.path):
            accept_str = self.headers['Accept']
            msg_str = json.dumps(actor_json, ensure_ascii=False)
            msg_str = convert_domains(calling_domain,
                                      referer_domain,
                                      msg_str, http_prefix,
                                      domain,
                                      onion_domain,
                                      i2p_domain)
            msg = msg_str.encode('utf-8')
            msglen = len(msg)
            if 'application/ld+json' in accept_str:
                set_headers(self, 'application/ld+json', msglen,
                            cookie, calling_domain, False)
            elif 'application/jrd+json' in accept_str:
                set_headers(self, 'application/jrd+json', msglen,
                            cookie, calling_domain, False)
            else:
                set_headers(self, 'application/activity+json', msglen,
                            cookie, calling_domain, False)
            write2(self, msg)
            fitness_performance(getreq_start_time, fitness,
                                '_GET', '_show_person_profile json',
                                debug)
            if debug:
                print('DEBUG: json actor sent')
        else:
            http_404(self, 87)
    return True


def show_roles(self, calling_domain: str, referer_domain: str,
               path: str, base_dir: str, http_prefix: str,
               domain: str, getreq_start_time,
               proxy_type: str, cookie: str, debug: str,
               curr_session, default_timeline: str,
               recent_posts_cache: {},
               cached_webfingers: {},
               yt_replace_domain: str,
               twitter_replacement_domain: str,
               icons_as_buttons: bool,
               access_keys: {},
               key_shortcuts: {}, city: str,
               signing_priv_key_pem: str,
               rss_icon_at_top: bool,
               shared_items_federated_domains: [],
               account_timezone: {},
               bold_reading_nicknames: {},
               max_recent_posts: int,
               translate: {},
               project_version: str,
               person_cache: {},
               show_published_date_only: bool,
               newswire: {},
               theme_name: str,
               dormant_months: int,
               peertube_instances: [],
               allow_local_network_access: bool,
               text_mode_banner: str,
               system_language: str,
               max_like_count: int,
               cw_lists: {},
               lists_enabled: {},
               content_license_url: str,
               buy_sites: {},
               max_shares_on_profile: int,
               sites_unavailable: [],
               no_of_books: int,
               auto_cw_cache: {},
               fitness: {},
               onion_domain: str,
               i2p_domain: str,
               mitm_servers: [],
               hide_recent_posts: {}) -> bool:
    """Show roles within profile screen
    """
    named_status = path.split('/users/')[1]
    if '/' not in named_status:
        return False

    post_sections = named_status.split('/')
    nickname = post_sections[0]
    actor_filename = acct_dir(base_dir, nickname, domain) + '.json'
    if not os.path.isfile(actor_filename):
        return False

    actor_json = load_json(actor_filename)
    if not actor_json:
        return False

    if actor_json.get('hasOccupation'):
        if request_http(self.headers, debug):
            get_person = \
                person_lookup(domain, path.replace('/roles', ''),
                              base_dir)
            if get_person:
                if key_shortcuts.get(nickname):
                    access_keys = key_shortcuts[nickname]

                roles_list = get_actor_roles_list(actor_json)
                city = get_spoofed_city(city, base_dir, nickname, domain)

                timezone = None
                if account_timezone.get(nickname):
                    timezone = account_timezone.get(nickname)
                bold_reading = False
                if bold_reading_nicknames.get(nickname):
                    bold_reading = True
                known_epicyon_instances = \
                    self.server.known_epicyon_instances
                instance_software = \
                    self.server.instance_software
                msg = \
                    html_profile(signing_priv_key_pem,
                                 rss_icon_at_top,
                                 icons_as_buttons,
                                 default_timeline,
                                 recent_posts_cache,
                                 max_recent_posts,
                                 translate,
                                 project_version,
                                 base_dir, http_prefix, True,
                                 get_person, 'roles',
                                 curr_session,
                                 cached_webfingers,
                                 person_cache,
                                 yt_replace_domain,
                                 twitter_replacement_domain,
                                 show_published_date_only,
                                 newswire,
                                 theme_name,
                                 dormant_months,
                                 peertube_instances,
                                 allow_local_network_access,
                                 text_mode_banner,
                                 debug,
                                 access_keys, city,
                                 system_language,
                                 max_like_count,
                                 shared_items_federated_domains,
                                 roles_list,
                                 None, None, cw_lists,
                                 lists_enabled,
                                 content_license_url,
                                 timezone, bold_reading,
                                 buy_sites, None,
                                 max_shares_on_profile,
                                 sites_unavailable,
                                 no_of_books,
                                 auto_cw_cache,
                                 known_epicyon_instances,
                                 mitm_servers,
                                 instance_software,
                                 hide_recent_posts)
                msg = msg.encode('utf-8')
                msglen = len(msg)
                set_headers(self, 'text/html', msglen,
                            cookie, calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time, fitness,
                                    '_GET', '_show_roles', debug)
        else:
            if secure_mode(curr_session, proxy_type, False,
                           self.server, self.headers, path):
                roles_list = get_actor_roles_list(actor_json)
                msg_str = json.dumps(roles_list, ensure_ascii=False)
                msg_str = convert_domains(calling_domain,
                                          referer_domain,
                                          msg_str, http_prefix,
                                          domain,
                                          onion_domain,
                                          i2p_domain)
                msg = msg_str.encode('utf-8')
                msglen = len(msg)
                protocol_str = \
                    get_json_content_from_accept(self.headers['Accept'])
                set_headers(self, protocol_str, msglen,
                            None, calling_domain, False)
                write2(self, msg)
                fitness_performance(getreq_start_time, fitness,
                                    '_GET', '_show_roles json', debug)
            else:
                http_404(self, 65)
        return True
    return False


def show_skills(self, calling_domain: str, referer_domain: str,
                path: str, base_dir: str, http_prefix: str,
                domain: str, getreq_start_time, proxy_type: str,
                cookie: str, debug: str, curr_session,
                default_timeline: str,
                recent_posts_cache: {},
                cached_webfingers: {},
                yt_replace_domain: str,
                twitter_replacement_domain: str,
                show_published_date_only: bool,
                icons_as_buttons: bool,
                allow_local_network_access: bool,
                access_keys: {},
                key_shortcuts: {},
                shared_items_federated_domains: [],
                signing_priv_key_pem: str,
                content_license_url: str,
                peertube_instances: [], city: str,
                account_timezone: {},
                bold_reading_nicknames: {},
                max_shares_on_profile: int,
                rss_icon_at_top: bool,
                max_recent_posts: int,
                translate: {},
                project_version: str,
                person_cache: {},
                newswire: {},
                theme_name: str,
                dormant_months: int,
                text_mode_banner: str,
                system_language: str,
                max_like_count: int,
                cw_lists: {},
                lists_enabled: {},
                buy_sites: [],
                sites_unavailable: [],
                no_of_books: int,
                auto_cw_cache: {},
                fitness: {},
                domain_full: str,
                onion_domain: str,
                i2p_domain: str,
                mitm_servers: [],
                hide_recent_posts: {}) -> bool:
    """Show skills on the profile screen
    """
    named_status = path.split('/users/')[1]
    if '/' in named_status:
        post_sections = named_status.split('/')
        nickname = post_sections[0]
        actor_filename = acct_dir(base_dir, nickname, domain) + '.json'
        if os.path.isfile(actor_filename):
            actor_json = load_json(actor_filename)
            if actor_json:
                if no_of_actor_skills(actor_json) > 0:
                    if request_http(self.headers, debug):
                        get_person = \
                            person_lookup(domain,
                                          path.replace('/skills', ''),
                                          base_dir)
                        if get_person:
                            if key_shortcuts.get(nickname):
                                access_keys = key_shortcuts[nickname]
                            actor_skills_list = \
                                get_occupation_skills(actor_json)
                            skills = \
                                get_skills_from_list(actor_skills_list)
                            city = get_spoofed_city(city, base_dir,
                                                    nickname, domain)
                            shared_items_fed_domains = \
                                shared_items_federated_domains
                            timezone = None
                            nick = nickname
                            if account_timezone.get(nick):
                                timezone = account_timezone.get(nick)
                            bold_reading = False
                            if bold_reading_nicknames.get(nick):
                                bold_reading = True
                            known_epicyon_instances = \
                                self.server.known_epicyon_instances
                            instance_software = \
                                self.server.instance_software
                            msg = \
                                html_profile(signing_priv_key_pem,
                                             rss_icon_at_top,
                                             icons_as_buttons,
                                             default_timeline,
                                             recent_posts_cache,
                                             max_recent_posts,
                                             translate,
                                             project_version,
                                             base_dir, http_prefix, True,
                                             get_person, 'skills',
                                             curr_session,
                                             cached_webfingers,
                                             person_cache,
                                             yt_replace_domain,
                                             twitter_replacement_domain,
                                             show_published_date_only,
                                             newswire,
                                             theme_name,
                                             dormant_months,
                                             peertube_instances,
                                             allow_local_network_access,
                                             text_mode_banner,
                                             debug,
                                             access_keys, city,
                                             system_language,
                                             max_like_count,
                                             shared_items_fed_domains,
                                             skills,
                                             None, None,
                                             cw_lists,
                                             lists_enabled,
                                             content_license_url,
                                             timezone, bold_reading,
                                             buy_sites,
                                             None,
                                             max_shares_on_profile,
                                             sites_unavailable,
                                             no_of_books,
                                             auto_cw_cache,
                                             known_epicyon_instances,
                                             mitm_servers,
                                             instance_software,
                                             hide_recent_posts)
                            msg = msg.encode('utf-8')
                            msglen = len(msg)
                            set_headers(self, 'text/html', msglen,
                                        cookie, calling_domain,
                                              False)
                            write2(self, msg)
                            fitness_performance(getreq_start_time, fitness,
                                                '_GET', '_show_skills',
                                                debug)
                    else:
                        if secure_mode(curr_session,
                                       proxy_type, False,
                                       self.server,
                                       self.headers,
                                       path):
                            actor_skills_list = \
                                get_occupation_skills(actor_json)
                            skills = \
                                get_skills_from_list(actor_skills_list)
                            msg_str = json.dumps(skills,
                                                 ensure_ascii=False)
                            msg_str = convert_domains(calling_domain,
                                                      referer_domain,
                                                      msg_str,
                                                      http_prefix,
                                                      domain,
                                                      onion_domain,
                                                      i2p_domain)
                            msg = msg_str.encode('utf-8')
                            msglen = len(msg)
                            accept_str = self.headers['Accept']
                            protocol_str = \
                                get_json_content_from_accept(accept_str)
                            set_headers(self, protocol_str, msglen, None,
                                        calling_domain, False)
                            write2(self, msg)
                            fitness_performance(getreq_start_time, fitness,
                                                '_GET',
                                                '_show_skills json',
                                                debug)
                        else:
                            http_404(self, 66)
                    return True
    actor = path.replace('/skills', '')
    actor_absolute = \
        get_instance_url(calling_domain, http_prefix, domain_full,
                         onion_domain, i2p_domain) + \
        actor
    redirect_headers(self, actor_absolute, cookie, calling_domain, 303)
    return True


def edit_profile2(self, calling_domain: str, path: str,
                  translate: {}, base_dir: str,
                  domain: str, port: int,
                  cookie: str,
                  peertube_instances: [],
                  access_keys: {},
                  key_shortcuts: {},
                  default_reply_interval_hrs: int,
                  default_timeline: str,
                  theme_name: str,
                  text_mode_banner: str,
                  user_agents_blocked: [],
                  crawlers_allowed: [],
                  cw_lists: {},
                  lists_enabled: {},
                  system_language: str,
                  min_images_for_accounts: [],
                  max_recent_posts: int,
                  reverse_sequence: bool,
                  buy_sites: [],
                  block_military: {},
                  block_government: {},
                  block_bluesky: {},
                  block_nostr: {},
                  block_federated_endpoints: []) -> bool:
    """Show the edit profile screen
    """
    if '/users/' in path and path.endswith('/editprofile'):
        nickname = get_nickname_from_actor(path)

        if '/users/' in path:
            if key_shortcuts.get(nickname):
                access_keys = key_shortcuts[nickname]

        msg = html_edit_profile(self.server, translate,
                                base_dir, path, domain, port,
                                default_timeline,
                                theme_name,
                                peertube_instances,
                                text_mode_banner,
                                user_agents_blocked,
                                crawlers_allowed,
                                access_keys,
                                default_reply_interval_hrs,
                                cw_lists,
                                lists_enabled,
                                system_language,
                                min_images_for_accounts,
                                max_recent_posts,
                                reverse_sequence,
                                buy_sites,
                                block_military,
                                block_government,
                                block_bluesky,
                                block_nostr,
                                block_federated_endpoints)
        if msg:
            msg = msg.encode('utf-8')
            msglen = len(msg)
            set_headers(self, 'text/html', msglen,
                        cookie, calling_domain, False)
            write2(self, msg)
        else:
            http_404(self, 105)
        return True
    return False
