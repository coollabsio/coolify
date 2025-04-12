__filename__ = "schedule.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Calendar"

import os
import time
from utils import data_dir
from utils import date_from_string_format
from utils import date_epoch
from utils import acct_handle_dir
from utils import has_object_dict
from utils import get_status_number
from utils import load_json
from utils import is_account_dir
from utils import acct_dir
from utils import remove_eol
from utils import date_utcnow
from outbox import post_message_to_outbox
from session import create_session
from threads import begin_thread
from siteactive import save_unavailable_sites


def _update_post_schedule(base_dir: str, handle: str, httpd,
                          max_scheduled_posts: int) -> None:
    """Checks if posts are due to be delivered and if so moves them to
    the outbox
    """
    schedule_index_filename = \
        acct_handle_dir(base_dir, handle) + '/schedule.index'
    if not os.path.isfile(schedule_index_filename):
        return

    # get the current time as an int
    curr_time = date_utcnow()
    days_since_epoch = (curr_time - date_epoch()).days

    schedule_dir = acct_handle_dir(base_dir, handle) + '/scheduled/'
    index_lines: list[str] = []
    delete_schedule_post = False
    nickname = handle.split('@')[0]
    shared_items_federated_domains = httpd.shared_items_federated_domains
    shared_item_federation_tokens = httpd.shared_item_federation_tokens
    try:
        with open(schedule_index_filename, 'r', encoding='utf-8') as fp_sched:
            for line in fp_sched:
                if ' ' not in line:
                    continue
                date_str = line.split(' ')[0]
                if 'T' not in date_str:
                    continue
                post_id1 = line.split(' ', 1)[1]
                post_id = remove_eol(post_id1)
                post_filename = schedule_dir + post_id + '.json'
                if delete_schedule_post:
                    # delete extraneous scheduled posts
                    if os.path.isfile(post_filename):
                        try:
                            os.remove(post_filename)
                        except OSError:
                            print('EX: ' +
                                  '_update_post_schedule unable to delete ' +
                                  str(post_filename))
                    continue
                # create the new index file
                index_lines.append(line)
                # convert string date to int
                post_time = \
                    date_from_string_format(date_str, ["%Y-%m-%dT%H:%M:%S%z"])
                post_time = post_time.replace(tzinfo=None)
                post_days_since_epoch = \
                    (post_time - date_epoch()).days
                if days_since_epoch < post_days_since_epoch:
                    continue
                if days_since_epoch == post_days_since_epoch:
                    if curr_time.time().hour < post_time.time().hour:
                        continue
                    if curr_time.time().minute < post_time.time().minute:
                        continue
                if not os.path.isfile(post_filename):
                    print('WARN: schedule missing post_filename=' +
                          post_filename)
                    index_lines.remove(line)
                    continue
                # load post
                post_json_object = load_json(post_filename)
                if not post_json_object:
                    print('WARN: schedule json not loaded')
                    index_lines.remove(line)
                    continue

                # set the published time
                # If this is not recent then http checks on the receiving side
                # will reject it
                _, published = get_status_number()
                if post_json_object.get('published'):
                    post_json_object['published'] = published
                if has_object_dict(post_json_object):
                    if post_json_object['object'].get('published'):
                        post_json_object['published'] = published

                print('Sending scheduled post ' + post_id)

                if nickname:
                    httpd.post_to_nickname = nickname

                # create session if needed
                curr_session = httpd.session
                curr_proxy_type = httpd.proxy_type
                if not curr_session:
                    curr_session = create_session(httpd.proxy_type)
                    httpd.session = curr_session
                if not curr_session:
                    continue

                if not post_message_to_outbox(curr_session,
                                              httpd.translate,
                                              post_json_object, nickname,
                                              httpd, base_dir,
                                              httpd.http_prefix,
                                              httpd.domain,
                                              httpd.domain_full,
                                              httpd.onion_domain,
                                              httpd.i2p_domain,
                                              httpd.port,
                                              httpd.recent_posts_cache,
                                              httpd.followers_threads,
                                              httpd.federation_list,
                                              httpd.send_threads,
                                              httpd.post_log,
                                              httpd.cached_webfingers,
                                              httpd.person_cache,
                                              httpd.allow_deletion,
                                              curr_proxy_type,
                                              httpd.project_version,
                                              httpd.debug,
                                              httpd.yt_replace_domain,
                                              httpd.twitter_replacement_domain,
                                              httpd.show_published_date_only,
                                              httpd.allow_local_network_access,
                                              httpd.city,
                                              httpd.system_language,
                                              shared_items_federated_domains,
                                              shared_item_federation_tokens,
                                              httpd.low_bandwidth,
                                              httpd.signing_priv_key_pem,
                                              httpd.peertube_instances,
                                              httpd.theme_name,
                                              httpd.max_like_count,
                                              httpd.max_recent_posts,
                                              httpd.cw_lists,
                                              httpd.lists_enabled,
                                              httpd.content_license_url,
                                              httpd.dogwhistles,
                                              httpd.min_images_for_accounts,
                                              httpd.buy_sites,
                                              httpd.sites_unavailable,
                                              httpd.max_recent_books,
                                              httpd.books_cache,
                                              httpd.max_cached_readers,
                                              httpd.auto_cw_cache,
                                              httpd.block_federated,
                                              httpd.mitm_servers,
                                              httpd.instance_software):
                    index_lines.remove(line)
                    try:
                        os.remove(post_filename)
                    except OSError:
                        print('EX: _update_post_schedule unable to delete ' +
                              str(post_filename))
                    continue

                # move to the outbox
                outbox_post_filename = \
                    post_filename.replace('/scheduled/', '/outbox/')
                os.rename(post_filename, outbox_post_filename)

                print('Scheduled post sent ' + post_id)

                index_lines.remove(line)
                if len(index_lines) > max_scheduled_posts:
                    delete_schedule_post = True
    except OSError as exc:
        print('EX: _update_post_schedule unable to read ' +
              schedule_index_filename + ' ' + str(exc))

    # write the new schedule index file
    schedule_index_file = \
        acct_handle_dir(base_dir, handle) + '/schedule.index'
    try:
        with open(schedule_index_file, 'w+',
                  encoding='utf-8') as fp_schedule:
            for line in index_lines:
                fp_schedule.write(line)
    except OSError:
        print('EX: _update_post_schedule unable to write ' +
              schedule_index_file)


def run_post_schedule(base_dir: str, httpd, max_scheduled_posts: int):
    """Dispatches scheduled posts
    """
    while True:
        time.sleep(60)
        # for each account
        dir_str = data_dir(base_dir)
        for _, dirs, _ in os.walk(dir_str):
            for account in dirs:
                if '@' not in account:
                    continue
                if not is_account_dir(account):
                    continue
                # scheduled posts index for this account
                schedule_index_filename = \
                    dir_str + '/' + account + '/schedule.index'
                if not os.path.isfile(schedule_index_filename):
                    continue
                _update_post_schedule(base_dir, account,
                                      httpd, max_scheduled_posts)
            break


def run_post_schedule_watchdog(project_version: str, httpd) -> None:
    """This tries to keep the scheduled post thread running even if it dies
    """
    print('THREAD: Starting scheduled post watchdog ' + project_version)
    post_schedule_original = \
        httpd.thrPostSchedule.clone(run_post_schedule)
    begin_thread(httpd.thrPostSchedule, 'run_post_schedule_watchdog')
    curr_sites_unavailable = httpd.sites_unavailable.copy()
    while True:
        time.sleep(20)

        # save the list of unavailable sites
        if str(curr_sites_unavailable) != str(httpd.sites_unavailable):
            save_unavailable_sites(httpd.base_dir, httpd.sites_unavailable)
            curr_sites_unavailable = httpd.sites_unavailable.copy()

        if httpd.thrPostSchedule.is_alive():
            continue
        httpd.thrPostSchedule.kill()
        print('THREAD: restarting scheduled post watchdog')
        httpd.thrPostSchedule = \
            post_schedule_original.clone(run_post_schedule)
        begin_thread(httpd.thrPostSchedule, 'run_post_schedule_watchdog')
        print('Restarting scheduled posts...')


def remove_scheduled_posts(base_dir: str, nickname: str, domain: str) -> None:
    """Removes any scheduled posts
    """
    # remove the index
    schedule_index_filename = \
        acct_dir(base_dir, nickname, domain) + '/schedule.index'
    if os.path.isfile(schedule_index_filename):
        try:
            os.remove(schedule_index_filename)
        except OSError:
            print('EX: remove_scheduled_posts unable to delete ' +
                  schedule_index_filename)
    # remove the scheduled posts
    scheduled_dir = acct_dir(base_dir, nickname, domain) + '/scheduled'
    if not os.path.isdir(scheduled_dir):
        return
    for scheduled_post_filename in os.listdir(scheduled_dir):
        file_path = os.path.join(scheduled_dir, scheduled_post_filename)
        if not os.path.isfile(file_path):
            continue
        try:
            os.remove(file_path)
        except OSError:
            print('EX: remove_scheduled_posts unable to delete ' +
                  file_path)
