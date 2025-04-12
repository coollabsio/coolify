__filename__ = "webapp_media.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Timeline"

import os
from utils import data_dir
from utils import string_ends_with
from utils import valid_url_prefix


def load_peertube_instances(base_dir: str, peertube_instances: []) -> None:
    """Loads peertube instances from file into the given list
    """
    peertube_list = None
    peertube_instances_filename = data_dir(base_dir) + '/peertube.txt'
    if os.path.isfile(peertube_instances_filename):
        try:
            with open(peertube_instances_filename, 'r',
                      encoding='utf-8') as fp_inst:
                peertube_str = fp_inst.read()
                if peertube_str:
                    peertube_str = peertube_str.replace('\r', '')
                    peertube_list = peertube_str.split('\n')
        except OSError as exc:
            print('EX: load_peertube_instances unable to read ' +
                  peertube_instances_filename + ' ' + str(exc))
    if not peertube_list:
        return
    for url in peertube_list:
        if url in peertube_instances:
            continue
        peertube_instances.append(url)


def _add_embedded_video_from_sites(content: str,
                                   peertube_instances: [],
                                   width: int, height: int,
                                   domain: str) -> str:
    """Adds embedded videos
    """
    if '<iframe' in content:
        return content

    if 'www.twitch.tv/' in content:
        url = content.split('www.twitch.tv/')[1]
        if '<' in url:
            channel = url.split('<')[0]
            if channel and \
               '/' not in channel and \
               '?' not in channel and \
               '=' not in channel and \
               ' ' not in channel:
                content += \
                    '<center>\n<span itemprop="video">\n' + \
                    '<iframe src="https://player.twitch.tv/?channel=' + \
                    channel + '&parent=' + domain + '" ' + \
                    'frameborder="0" allowfullscreen="true" ' + \
                    'scrolling="no" height="' + str(height) + \
                    '" width="' + str(width) + \
                    '" sandbox="allow-scripts allow-same-origin">' + \
                    '</iframe></span>\n</center>\n'
                return content

    if '>vimeo.com/' in content:
        url = content.split('>vimeo.com/')[1]
        if '<' in url:
            url = url.split('<')[0]
            if url:
                content += \
                    "<center>\n<span itemprop=\"video\">\n" + \
                    "<iframe loading=\"lazy\" decoding=\"async\" " + \
                    "src=\"https://player.vimeo.com/video/" + \
                    url + "\" width=\"" + str(width) + \
                    "\" height=\"" + str(height) + \
                    "\" frameborder=\"0\" allow=\"" + \
                    "fullscreen\" allowfullscreen " + \
                    "tabindex=\"10\" " + \
                    "sandbox=\"allow-scripts allow-same-origin\">" + \
                    "</iframe>\n</span>\n</center>\n"
                return content

    video_site = 'https://www.youtube.com'
    if 'https://m.youtube.com' in content:
        content = content.replace('https://m.youtube.com', video_site)
    if '"' + video_site in content:
        url = content.split('"' + video_site)[1]
        if '"' in url:
            url = url.split('"')[0]
            if url and '/channel/' not in url and '/playlist' not in url:
                url = url.replace('/watch?v=', '/embed/')
                if '&' in url:
                    url = url.split('&')[0]
                if '?utm_' in url:
                    url = url.split('?utm_')[0]
                content += \
                    "<center>\n<span itemprop=\"video\">\n" + \
                    "<iframe loading=\"lazy\" " + \
                    "decoding=\"async\" src=\"" + \
                    video_site + url + "\" width=\"" + str(width) + \
                    "\" height=\"" + str(height) + \
                    "\" frameborder=\"0\" allow=\"fullscreen\" " + \
                    "allowfullscreen tabindex=\"10\" " + \
                    "sandbox=\"allow-scripts allow-same-origin\">" + \
                    "</iframe>\n</span></center>\n"
                return content

    video_site = 'https://youtu.be/'
    if '"' + video_site in content:
        url = content.split('"' + video_site)[1]
        if '"' in url:
            url = url.split('"')[0]
            if url and '/channel/' not in url and '/playlist' not in url:
                url = 'embed/' + url
                if '&' in url:
                    url = url.split('&')[0]
                if '?utm_' in url:
                    url = url.split('?utm_')[0]
                video_site = 'https://www.youtube.com/'
                content += \
                    "<center>\n<span itemprop=\"video\">\n" + \
                    "<iframe loading=\"lazy\" " + \
                    "decoding=\"async\" src=\"" + \
                    video_site + url + "\" width=\"" + str(width) + \
                    "\" height=\"" + str(height) + \
                    "\" frameborder=\"0\" allow=\"fullscreen\" " + \
                    "allowfullscreen tabindex=\"10\" " + \
                    "sandbox=\"allow-scripts allow-same-origin\">" + \
                    "</iframe>\n</span></center>\n"
                return content

    invidious_sites = (
        'https://invidious.snopyta.org',
        'https://yewtu.be',
        'https://tube.connect.cafe',
        'https://invidious.kavin.rocks',
        'https://invidiou.site',
        'https://invidious.tube',
        'https://invidious.xyz',
        'https://invidious.zapashcanon.fr',
        'http://c7hqkpkpemu6e7emz5b4vy' +
        'z7idjgdvgaaa3dyimmeojqbgpea3xqjoid.onion',
        'http://axqzx4s6s54s32yentfqojs3x5i7faxza6xo3ehd4' +
        'bzzsg2ii4fv2iid.onion'
    )
    for video_site in invidious_sites:
        if '"' + video_site in content:
            url = content.split('"' + video_site)[1]
            if '"' in url:
                url = url.split('"')[0]
                if not url:
                    continue
                url = url.replace('/watch?v=', '/embed/')
                if '&' in url:
                    url = url.split('&')[0]
                if '?utm_' in url:
                    url = url.split('?utm_')[0]
                # explicitly turn off autoplay
                if '?' in url:
                    if '&autoplay=' not in url:
                        url += '&autoplay=0'
                    else:
                        url = url.replace('&autoplay=1', '&autoplay=0')
                else:
                    if '?autoplay=' not in url:
                        url += '?autoplay=0'
                    else:
                        url = url.replace('?autoplay=1', '?autoplay=0')
                if not url:
                    continue
                content += \
                    "<center>\n<span itemprop=\"video\">\n" + \
                    "<iframe loading=\"lazy\" " + \
                    "decoding=\"async\" src=\"" + \
                    video_site + url + "\" width=\"" + \
                    str(width) + "\" height=\"" + str(height) + \
                    "\" frameborder=\"0\" allow=\"fullscreen\" " + \
                    "allowfullscreen tabindex=\"10\" " + \
                    "sandbox=\"allow-scripts allow-same-origin\">" + \
                    "</iframe>\n</span>\n</center>\n"
                return content

    video_site = 'https://media.ccc.de'
    if '"' + video_site in content:
        url = content.split('"' + video_site)[1]
        if '"' in url:
            url = url.split('"')[0]
            if url:
                video_site_settings = ''
                if '#' in url:
                    video_site_settings = '#' + url.split('#', 1)[1]
                    url = url.split('#')[0]
                if not url.endswith('/oembed'):
                    url = url + '/oembed'
                url += video_site_settings
                content += \
                    "<center>\n<span itemprop=\"video\">\n" + \
                    "<iframe loading=\"lazy\" " + \
                    "decoding=\"async\" src=\"" + \
                    video_site + url + "\" width=\"" + \
                    str(width) + "\" height=\"" + str(height) + \
                    "\" frameborder=\"0\" allow=\"fullscreen\" " + \
                    "allowfullscreen tabindex=\"10\" " + \
                    "sandbox=\"allow-scripts allow-same-origin\">" + \
                    "</iframe>\n</span>\n</center>\n"
                return content

    if '"https://' in content:
        if peertube_instances:
            # only create an embedded video for a limited set of
            # peertube sites.
            peertube_sites = peertube_instances
        else:
            # A default minimal set of peertube instances
            # Also see https://peertube_isolation.frama.io/list/ for
            # adversarial instances. Nothing in that list should be
            # in the defaults below.
            peertube_sites = (
                'share.tube',
                'visionon.tv',
                'anarchy.tube',
                'peertube.fr',
                'video.nerdcave.site',
                'kolektiva.media',
                'peertube.social',
                'videos.lescommuns.org',
                'neat.tube'
            )
        for site in peertube_sites:
            site = site.strip()
            if not site:
                continue
            if len(site) < 5:
                continue
            if '.' not in site:
                continue
            site_str = site
            if site.startswith('http://'):
                site = site.replace('http://', '')
            elif site.startswith('https://'):
                site = site.replace('https://', '')
            if site.endswith('.onion') or site.endswith('.i2p'):
                site_str = 'http://' + site
            else:
                site_str = 'https://' + site
            site_str = '"' + site_str
            if site_str not in content:
                continue
            url = content.split(site_str)[1]
            if '"' not in url:
                continue
            url = url.split('"')[0]
            if not url:
                continue
            possible_endings = (
                '/trending', '/home', '/overview',
                '/recently-added', '/local', '/about')
            if string_ends_with(url, possible_endings):
                # ignore various peertube endpoints
                continue
            if '/c/' in url or '/m/' in url:
                # don't try to embed peertube channel page
                continue
            if '?sort=' in url:
                # don't try to embed a sorted list
                continue
            if '/w/' in url:
                if '/videos/' not in url:
                    url = url.replace('/w/', '/videos/embed/')
                else:
                    url = url.replace('/w/', '/embed/')
            url = url.replace('/watch/', '/embed/')

            content += \
                "<center>\n<span itemprop=\"video\">\n" + \
                "<iframe loading=\"lazy\" decoding=\"async\" " + \
                "sandbox=\"allow-same-origin " + \
                "allow-scripts\" src=\"https://" + \
                site + url + "\" width=\"" + str(width) + \
                "\" height=\"" + str(height) + \
                "\" frameborder=\"0\" allow=\"" + \
                "fullscreen\" allowfullscreen tabindex=\"10\" " + \
                "sandbox=\"allow-scripts allow-same-origin\">" + \
                "</iframe>\n</span>\n</center>\n"
            return content
    return content


def _add_embedded_audio(translate: {}, content: str) -> str:
    """Adds embedded audio for mp3/ogg/opus/wav
    """
    if not ('.mp3' in content or
            '.ogg' in content or
            '.wav' in content or
            '.opus' in content or
            '.spx' in content or
            '.flac' in content):
        return content

    if '<audio ' in content:
        return content

    extension = '.mp3'
    if '.ogg' in content:
        extension = '.ogg'
    elif '.wav' in content:
        extension = '.wav'
    elif '.opus' in content:
        extension = '.opus'
    elif '.spx' in content:
        extension = '.spx'
    elif '.flac' in content:
        extension = '.flac'

    words = content.strip('\n').split(' ')
    for wrd in words:
        if extension not in wrd:
            continue
        wrd = wrd.replace('href="', '').replace('">', '')
        if wrd.endswith('.'):
            wrd = wrd[:-1]
        if wrd.endswith('"'):
            wrd = wrd[:-1]
        if wrd.endswith(';'):
            wrd = wrd[:-1]
        if wrd.endswith(':'):
            wrd = wrd[:-1]
        if not wrd.endswith(extension):
            continue

        if not valid_url_prefix(wrd):
            continue
        content += \
            '<center>\n<span itemprop="audio">' + \
            '<audio controls tabindex="10">\n' + \
            '<source src="' + wrd + '" type="audio/' + \
            extension.replace('.', '') + '">' + \
            translate['Your browser does not support the audio element.'] + \
            '</audio>\n</span>\n</center>\n'
    return content


def _add_embedded_video(translate: {}, content: str) -> str:
    """Adds embedded video for mp4/webm/ogv
    """
    if not ('.mp4' in content or '.webm' in content or '.ogv' in content):
        return content

    if '<video ' in content:
        return content

    extension = '.mp4'
    if '.webm' in content:
        extension = '.webm'
    elif '.ogv' in content:
        extension = '.ogv'

    words = content.strip('\n').split(' ')
    for wrd in words:
        if extension not in wrd:
            continue
        wrd = wrd.replace('href="', '').replace('">', '')
        if wrd.endswith('.'):
            wrd = wrd[:-1]
        if wrd.endswith('"'):
            wrd = wrd[:-1]
        if wrd.endswith(';'):
            wrd = wrd[:-1]
        if wrd.endswith(':'):
            wrd = wrd[:-1]
        if not wrd.endswith(extension):
            continue
        if not valid_url_prefix(wrd):
            continue
        content += \
            '<center><span itemprop="video">\n' + \
            '<figure id="videoContainer" ' + \
            'data-fullscreen="false">\n' + \
            '    <video id="video" controls ' + \
            'preload="metadata" tabindex="10">\n' + \
            '<source src="' + wrd + '" type="video/' + \
            extension.replace('.', '') + '">\n' + \
            translate['Your browser does not support the video element.'] + \
            '</video>\n</figure>\n</span>\n</center>\n'
    return content


def add_embedded_elements(translate: {}, content: str,
                          peertube_instances: [], domain: str) -> str:
    """Adds embedded elements for various media types
    """
    content = _add_embedded_video_from_sites(content,
                                             peertube_instances,
                                             400, 300, domain)
    content = _add_embedded_audio(translate, content)
    return _add_embedded_video(translate, content)
