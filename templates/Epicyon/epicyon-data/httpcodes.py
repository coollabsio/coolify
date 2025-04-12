__filename__ = "httpcodes.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Core"

import time


def write2(self, msg) -> bool:
    tries = 0
    while tries < 5:
        try:
            self.wfile.write(msg)
            return True
        except BrokenPipeError as ex:
            if self.server.debug:
                print('EX: _write error ' + str(tries) + ' ' + str(ex))
            break
        except BaseException as ex:
            print('EX: _write error ' + str(tries) + ' ' + str(ex))
            time.sleep(0.5)
        tries += 1
    return False


def _http_return_code(self, http_code: int, http_description: str,
                      long_description: str, etag: str) -> None:
    msg = \
        '<html><head><title>' + str(http_code) + '</title></head>' + \
        '<body bgcolor="linen" text="black">' + \
        '<div style="font-size: 400px; ' + \
        'text-align: center;">' + str(http_code) + '</div>' + \
        '<div style="font-size: 128px; ' + \
        'text-align: center; font-variant: ' + \
        'small-caps;"><p role="alert">' + str(http_description) + \
        '</p></div>' + \
        '<div style="text-align: center;" aria-live="polite">' + \
        str(long_description) + '</div></body></html>'
    msg = msg.encode('utf-8')
    self.send_response(http_code)
    self.send_header('Content-Type', 'text/html; charset=utf-8')
    msg_len_str = str(len(msg))
    self.send_header('Content-Length', msg_len_str)
    if etag:
        self.send_header('ETag', etag)
    self.end_headers()
    if not write2(self, msg):
        print('Error when showing ' + str(http_code))


def http_200(self) -> None:
    if self.server.translate:
        ok_str = self.server.translate['This is nothing ' +
                                       'less than an utter triumph']
        _http_return_code(self, 200, self.server.translate['Ok'],
                          ok_str, None)
    else:
        _http_return_code(self, 200, 'Ok',
                          'This is nothing less ' +
                          'than an utter triumph', None)


def http_401(self, post_msg: str) -> None:
    if self.server.translate:
        if self.server.translate.get(post_msg):
            ok_str = self.server.translate[post_msg]
        else:
            ok_str = post_msg
        _http_return_code(self, 401,
                          self.server.translate['Unauthorized'],
                          ok_str, None)
    else:
        _http_return_code(self, 401, 'Unauthorized',
                          post_msg, None)


def http_402(self) -> None:
    if self.server.translate:
        text = self.server.translate["It's time to splash that cash"]
        _http_return_code(self, 402,
                          self.server.translate['Payment required'],
                          text, None)
    else:
        text = "It's time to splash that cash"
        _http_return_code(self, 402, 'Payment required', text, None)


def http_201(self, etag: str) -> None:
    if self.server.translate:
        done_str = self.server.translate['It is done']
        _http_return_code(self, 201,
                          self.server.translate['Created'], done_str,
                          etag)
    else:
        _http_return_code(self, 201, 'Created', 'It is done', etag)


def http_207(self) -> None:
    if self.server.translate:
        multi_str = self.server.translate['Lots of things']
        _http_return_code(self, 207,
                          self.server.translate['Multi Status'],
                          multi_str, None)
    else:
        _http_return_code(self, 207, 'Multi Status',
                          'Lots of things', None)


def http_403(self) -> None:
    if self.server.translate:
        _http_return_code(self, 403, self.server.translate['Forbidden'],
                          self.server.translate["You're not allowed"],
                          None)
    else:
        _http_return_code(self, 403, 'Forbidden',
                          "You're not allowed", None)


def http_404(self, ref: int) -> None:
    if self.server.translate:
        text = \
            self.server.translate['These are not the ' +
                                  'droids you are ' +
                                  'looking for'] + \
            ' ' + str(ref)
        _http_return_code(self, 404,
                          self.server.translate['Not Found'],
                          text, None)
    else:
        text = \
            'These are not the droids you are looking for ' + str(ref)
        _http_return_code(self, 404, 'Not Found', text, None)


def http_304(self) -> None:
    if self.server.translate:
        _http_return_code(self, 304, self.server.translate['Not changed'],
                          self.server.translate['The contents of ' +
                                                'your local cache ' +
                                                'are up to date'],
                          None)
    else:
        _http_return_code(self, 304, 'Not changed',
                          'The contents of ' +
                          'your local cache ' +
                          'are up to date',
                          None)


def http_400(self) -> None:
    if self.server.translate:
        _http_return_code(self, 400,
                          self.server.translate['Bad Request'],
                          self.server.translate['Better luck ' +
                                                'next time'],
                          None)
    else:
        _http_return_code(self, 400, 'Bad Request',
                          'Better luck next time', None)


def http_503(self) -> None:
    if self.server.translate:
        busy_str = \
            self.server.translate['The server is busy. ' +
                                  'Please try again later']
        _http_return_code(self, 503,
                          self.server.translate['Unavailable'],
                          busy_str, None)
    else:
        _http_return_code(self, 503, 'Unavailable',
                          'The server is busy. Please try again ' +
                          'later', None)
