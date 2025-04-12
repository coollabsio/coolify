__filename__ = "webapp_question.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Web Interface"

import os
import urllib.parse
from question import is_question
from utils import remove_id_ending
from utils import acct_dir
from utils import text_in_file


def insert_question(base_dir: str, translate: {},
                    nickname: str, domain: str, content: str,
                    post_json_object: {}, page_number: int) -> str:
    """ Inserts question selection into a post
    """
    if not is_question(post_json_object):
        return content
    if len(post_json_object['object']['oneOf']) == 0:
        return content
    message_id = remove_id_ending(post_json_object['id'])
    if '#' in message_id:
        message_id = message_id.split('#', 1)[0]
    page_number_str = ''
    if page_number:
        page_number_str = '?page=' + str(page_number)

    votes_filename = \
        acct_dir(base_dir, nickname, domain) + '/questions.txt'

    show_question_results = False
    if os.path.isfile(votes_filename):
        if text_in_file(message_id, votes_filename):
            show_question_results = True

    if not show_question_results:
        # show the question options
        content += '<div class="question">'
        content += \
            '<form method="POST" action="/users/' + \
            nickname + '/question' + page_number_str + '">\n'
        content += \
            '<input type="hidden" name="messageId" value="' + \
            message_id + '">\n<br>\n'
        for choice in post_json_object['object']['oneOf']:
            if not choice.get('type'):
                continue
            if not choice.get('name'):
                continue
            quoted_name = urllib.parse.quote_plus(choice['name'])
            content += \
                '<input type="radio" name="answer" value="' + \
                quoted_name + '" tabindex="10"> ' + \
                choice['name'] + '<br><br>\n'
        content += \
            '<input type="submit" value="' + \
            translate['Vote'] + '" class="vote" tabindex="10"><br><br>\n'
        content += '</form>\n</div>\n'
    else:
        # show the responses to a question
        content += '<div class="questionresult">\n'

        # get the maximum number of votes
        max_votes = 1
        for question_option in post_json_object['object']['oneOf']:
            if not question_option.get('name'):
                continue
            if not question_option.get('replies'):
                continue
            votes = 0
            try:
                votes = int(question_option['replies']['totalItems'])
            except BaseException:
                print('EX: insert_question unable to convert to int')
            if votes > max_votes:
                max_votes = int(votes+1)

        # show the votes as sliders
        for question_option in post_json_object['object']['oneOf']:
            if not question_option.get('name'):
                continue
            if not question_option.get('replies'):
                continue
            votes = 0
            try:
                votes = int(question_option['replies']['totalItems'])
            except BaseException:
                print('EX: insert_question unable to convert to int 2')
            votes_percent = str(int(votes * 100 / max_votes))

            content += \
                '<p>\n' + \
                '  <label class="labels">' + \
                question_option['name'] + '</label><br>\n' + \
                '  <svg class="voteresult">\n' + \
                '    <rect width="' + votes_percent + \
                '%" class="voteresultbar" />\n' + \
                '  </svg>' + \
                '  <label class="labels">' + votes_percent + '%</label>\n' + \
                '</p>\n'

        content += '</div>\n'
    return content
