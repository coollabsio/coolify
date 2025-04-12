__filename__ = "media.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Timeline"

import os
import time
import datetime
import subprocess
import random
from random import randint
from hashlib import sha1
from auth import create_password
from utils import date_epoch
from utils import date_utcnow
from utils import safe_system_string
from utils import get_base_content_from_post
from utils import get_full_domain
from utils import get_image_extensions
from utils import get_video_extensions
from utils import get_audio_extensions
from utils import get_media_extensions
from utils import has_object_dict
from utils import acct_dir
from utils import get_watermark_file
from shutil import copyfile
from shutil import rmtree
from shutil import move
from city import spoof_geolocation


# music file ID3 v1 genres
music_genre = {
    0: "Blues",
    96: "Big Band",
    1: "Classic Rock",
    97: "Chorus",
    2: "Country",
    98: "Easy Listening",
    3: "Dance",
    99: "Acoustic",
    4: "Disco",
    100: "Humour",
    5: "Funk",
    101: "Speech",
    6: "Grunge",
    102: "Chanson",
    7: "Hip Hop",
    103: "Opera",
    8: "Jazz",
    104: "Chamber Music",
    9: "Metal",
    105: "Sonata",
    10: "New Age",
    106: "Symphony",
    11: "Oldies",
    107: "Booty Bass",
    12: "Other",
    108: "Primus",
    13: "Pop",
    109: "Porn Groove",
    14: "RnB",
    110: "Satire",
    15: "Rap",
    111: "Slow Jam",
    16: "Reggae",
    112: "Club",
    17: "Rock",
    113: "Tango",
    18: "Techno",
    114: "Samba",
    19: "Industrial",
    115: "Folklore",
    20: "Alternative",
    116: "Ballad",
    21: "Ska",
    117: "Power Ballad",
    22: "Death Metal",
    118: "Rhythmic Soul",
    23: "Pranks",
    119: "Freestyle",
    24: "Soundtrack",
    120: "Duet",
    25: "Euro-Techno",
    121: "Punk Rock",
    26: "Ambient",
    122: "Drum Solo",
    27: "Trip Hop",
    123: "A Cappella",
    28: "Vocal",
    124: "Euro House",
    29: "Jazz Funk",
    125: "Dance Hall",
    30: "Fusion",
    126: "Goa",
    31: "Trance",
    127: "Drum and Bass",
    32: "Classical",
    128: "Club House",
    33: "Instrumental",
    129: "Hardcore",
    34: "Acid",
    130: "Terror",
    35: "House",
    131: "Indie",
    36: "Game",
    132: "BritPop",
    37: "Sound Clip",
    133: "Negerpunk",
    38: "Gospel",
    134: "Polsk Punk",
    39: "Noise",
    135: "Beat",
    40: "AlternRock",
    136: "Christian Gangsta Rap",
    41: "Bass",
    137: "Heavy Metal",
    42: "Soul",
    138: "Black Metal",
    43: "Punk",
    139: "Crossover",
    44: "Space",
    140: "Contemporary Christian",
    45: "Meditative",
    141: "Christian Rock",
    46: "Instrumental Pop",
    142: "Merengue",
    47: "Instrumental Rock",
    143: "Salsa",
    48: "Ethnic",
    144: "Thrash Metal",
    49: "Gothic",
    145: "Anime",
    50: "Darkwave",
    146: "JPop",
    51: "Techno Industrial",
    147: "Synthpop",
    52: "Electronic",
    148: "Abstract",
    53: "Pop Folk",
    149: "Art Rock",
    54: "Eurodance",
    150: "Baroque",
    55: "Dream",
    151: "Bhangra",
    56: "Southern Rock",
    152: "Big Beat",
    57: "Comedy",
    153: "Breakbeat",
    58: "Cult",
    154: "Chillout",
    59: "Gangsta Rap",
    155: "Downtempo",
    60: "Top 40",
    156: "Dub",
    61: "Christian Rap",
    157: "EBM",
    62: "Pop Funk",
    158: "Eclectic",
    63: "Jungle",
    159: "Electro",
    64: "Native American",
    160: "Electroclash",
    65: "Cabaret",
    161: "Emo",
    66: "New Wave",
    162: "Experimental",
    67: "Psychedelic",
    163: "Garage",
    68: "Rave",
    164: "Global",
    69: "Showtunes",
    165: "IDM",
    70: "Trailer",
    166: "Illbient",
    71: "Lo Fi",
    167: "Industro Goth",
    72: "Tribal",
    168: "Jam Band",
    73: "Acid Punk",
    169: "Krautrock",
    74: "Acid Jazz",
    170: "Leftfield",
    75: "Polka",
    171: "Lounge",
    76: "Retro",
    172: "Math Rock",
    77: "Musical",
    173: "New Romantic",
    78: "Rock and Roll",
    174: "Nu-Breakz",
    79: "Hard Rock",
    175: "Post Punk",
    80: "Folk",
    176: "Post Rock",
    81: "Folk Rock",
    177: "Psytrance",
    82: "National Folk",
    178: "Shoegaze",
    83: "Swing",
    179: "Space Rock",
    84: "Fast Fusion",
    180: "Trop Rock",
    85: "Bebob",
    181: "World Music",
    86: "Latin",
    182: "Neoclassical",
    87: "Revival",
    183: "Audiobook",
    88: "Celtic",
    184: "Audio Theatre",
    89: "Bluegrass",
    185: "Neue Deutsche Welle",
    90: "Avantgarde",
    186: "Podcast",
    91: "Gothic Rock",
    187: "Indie Rock",
    92: "Progressive Rock",
    188: "G Funk",
    93: "Psychedelic Rock",
    189: "Dubstep",
    94: "Symphonic Rock",
    190: "Garage Rock",
    95: "Slow Rock",
    191: "Psybient"
}


def _get_blur_hash() -> str:
    """You may laugh, but this is a lot less computationally intensive,
    especially on large images, while still providing some visual variety
    in the timeline
    """
    hashes = [
        "UfGuaW01%gRi%MM{azofozo0V@xuozn#ofs.",
        "UFD]o8-;9FIU~qD%j[%M-;j[ofWB?bt7IURj",
        "UyO|v_1#im=s%y#U%OxDwRt3W9R-ogjHj[WX",
        "U96vAQt6H;WBt7ofWBa#MbWBo#j[byaze-oe",
        "UJKA.q01M|IV%LM|RjNGIVj[f6oLjrofaeof",
        "U9MPjn]?~Cxut~.PS1%1xXIo0fEer_$*^jxG",
        "UtLENXWCRjju~qayaeaz00j[ofayIVkCkCfQ",
        "UHGbeg-pbzWZ.ANI$wsQ$H-;E9W?0Nx]?FjE",
        "UcHU%#4n_ND%?bxatRWBIU%MazxtNaRjs:of",
        "ULR:TsWr~6xZofWWf6s-~6oK9eR,oes-WXNJ",
        "U77VQB-:MaMx%L%MogRkMwkCxuoIS*WYjEsl",
        "U%Nm{8R+%MxuE1t6WBNG-=RjoIt6~Vj]RkR*",
        "UCM7u;?boft7oft7ayj[~qt7WBoft7oft7Rj"
    ]
    return random.choice(hashes)


def _replace_silo_domain(post_json_object: {},
                         silo_domain: str, replacement_domain: str,
                         system_language: str) -> None:
    """Replace a silo domain with a replacement domain
    """
    if not replacement_domain:
        return
    if not has_object_dict(post_json_object):
        return
    if not post_json_object['object'].get('content'):
        return
    content_str = get_base_content_from_post(post_json_object, system_language)
    if '/' + silo_domain not in content_str:
        if '.' + silo_domain not in content_str:
            return
    content_str = content_str.replace('/' + silo_domain,
                                      '/' + replacement_domain)
    content_str = content_str.replace('.' + silo_domain,
                                      '.' + replacement_domain)
    post_json_object['object']['content'] = content_str
    if post_json_object['object'].get('contentMap'):
        post_json_object['object']['contentMap'][system_language] = content_str


def replace_you_tube(post_json_object: {}, replacement_domain: str,
                     system_language: str) -> None:
    """Replace YouTube with a replacement domain
    This denies Google some, but not all, tracking data
    """
    _replace_silo_domain(post_json_object, 'youtube.com',
                         replacement_domain, system_language)


def replace_twitter(post_json_object: {}, replacement_domain: str,
                    system_language: str) -> None:
    """Replace Twitter with a replacement domain
    This allows you to view twitter posts without having a twitter account
    """
    twitter_domains = ('x.com', 'twitter.com')
    for tw_domain in twitter_domains:
        _replace_silo_domain(post_json_object, tw_domain,
                             replacement_domain, system_language)


def _remove_meta_data(image_filename: str, output_filename: str) -> None:
    """Attempts to do this with pure python didn't work well,
    so better to use a dedicated tool if one is installed
    """
    copyfile(image_filename, output_filename)
    if not os.path.isfile(output_filename):
        print('ERROR: unable to remove metadata from ' + image_filename)
        return
    if os.path.isfile('/usr/bin/exiftool'):
        print('Removing metadata from ' + output_filename + ' using exiftool')
        cmd = 'exiftool -all= ' + safe_system_string(output_filename)
        os.system(cmd)  # nosec
    elif os.path.isfile('/usr/bin/mogrify'):
        print('Removing metadata from ' + output_filename + ' using mogrify')
        cmd = \
            '/usr/bin/mogrify -strip ' + safe_system_string(output_filename)
        os.system(cmd)  # nosec


def _spoof_meta_data(base_dir: str, nickname: str, domain: str,
                     output_filename: str, spoof_city: str,
                     content_license_url: str) -> None:
    """Spoof image metadata using a decoy model for a given city
    """
    if not os.path.isfile(output_filename):
        print('ERROR: unable to spoof metadata within ' + output_filename)
        return

    # get the random seed used to generate a unique pattern for this account
    decoy_seed_filename = acct_dir(base_dir, nickname, domain) + '/decoyseed'
    decoy_seed = 63725
    if os.path.isfile(decoy_seed_filename):
        try:
            with open(decoy_seed_filename, 'r', encoding='utf-8') as fp_seed:
                decoy_seed = int(fp_seed.read())
        except OSError:
            print('EX: _spoof_meta_data unable to read ' + decoy_seed_filename)
    else:
        decoy_seed = randint(10000, 10000000000000000)
        try:
            with open(decoy_seed_filename, 'w+',
                      encoding='utf-8') as fp_seed:
                fp_seed.write(str(decoy_seed))
        except OSError:
            print('EX: _spoof_meta_data unable to write ' +
                  decoy_seed_filename)

    if os.path.isfile('/usr/bin/exiftool'):
        print('Spoofing metadata in ' + output_filename + ' using exiftool')
        curr_time_adjusted = \
            date_utcnow() - \
            datetime.timedelta(minutes=randint(2, 120))
        published = curr_time_adjusted.strftime("%Y:%m:%d %H:%M:%S+00:00")
        (latitude, longitude, latitude_ref, longitude_ref,
         cam_make, cam_model, cam_serial_number) = \
            spoof_geolocation(base_dir, spoof_city, curr_time_adjusted,
                              decoy_seed, None, None)
        safe_handle = safe_system_string(nickname + '@' + domain)
        safe_license_url = safe_system_string(content_license_url)
        if os.system('exiftool -artist=@"' + safe_handle + '" ' +
                     '-Make="' + cam_make + '" ' +
                     '-Model="' + cam_model + '" ' +
                     '-Comment="' + str(cam_serial_number) + '" ' +
                     '-DateTimeOriginal="' + published + '" ' +
                     '-FileModifyDate="' + published + '" ' +
                     '-CreateDate="' + published + '" ' +
                     '-GPSLongitudeRef=' + longitude_ref + ' ' +
                     '-GPSAltitude=0 ' +
                     '-GPSLongitude=' + str(longitude) + ' ' +
                     '-GPSLatitudeRef=' + latitude_ref + ' ' +
                     '-GPSLatitude=' + str(latitude) + ' ' +
                     '-copyright="' + safe_license_url + '" ' +
                     '-Comment="" ' +
                     output_filename) != 0:  # nosec
            print('ERROR: exiftool failed to run')
    else:
        print('ERROR: exiftool is not installed')
        return


def get_music_metadata(filename: str) -> {}:
    """Returns metadata for a music file
    """
    result = None
    safe_filename = safe_system_string(filename)
    try:
        result = subprocess.run(['exiftool', '-v3', safe_filename],
                                stdout=subprocess.PIPE)
    except BaseException as ex:
        print('EX: get_music_metadata failed ' + str(ex))
    if not result:
        return {}
    if not result.stdout:
        return {}
    try:
        id3_lines = result.stdout.decode('utf-8').split('\n')
    except BaseException:
        print('EX: get_music_metadata unable to decode output')
        return {}
    fieldnames = (
        'Title', 'Artist', 'Genre', 'Track', 'Album', 'Length', 'Band'
    )
    music_metadata = {}
    for line in id3_lines:
        for field in fieldnames:
            if field + ' = ' not in line:
                continue
            field_value = line.split(field + ' = ')[1]
            if '>' in field_value:
                field_value = field_value.split('>')[0].strip()
            if ':' in field_value and ' ' in field_value:
                words = field_value.split(' ')
                new_value = ''
                for wrd in words:
                    if ':' not in wrd:
                        new_value += wrd + ' '
                field_value = new_value.strip()
            if field == 'Genre' and field_value.isdigit():
                if music_genre.get(int(field_value)):
                    field_value = music_genre[int(field_value)]
            music_metadata[field.lower()] = field_value
    return music_metadata


def convert_image_to_low_bandwidth(image_filename: str) -> None:
    """Converts an image to a low bandwidth version
    """
    low_bandwidth_filename = image_filename + '.low'
    if os.path.isfile(low_bandwidth_filename):
        try:
            os.remove(low_bandwidth_filename)
        except OSError:
            print('EX: convert_image_to_low_bandwidth unable to delete ' +
                  low_bandwidth_filename)

    cmd = \
        '/usr/bin/convert +noise Multiplicative ' + \
        '-evaluate median 10% -dither Floyd-Steinberg ' + \
        '-monochrome  ' + safe_system_string(image_filename) + \
        ' ' + safe_system_string(low_bandwidth_filename)
    print('Low bandwidth image conversion: ' + cmd)
    subprocess.call(cmd, shell=True)
    # wait for conversion to happen
    ctr = 0
    while not os.path.isfile(low_bandwidth_filename):
        print('Waiting for low bandwidth image conversion ' + str(ctr))
        time.sleep(0.2)
        ctr += 1
        if ctr > 100:
            print('WARN: timed out waiting for low bandwidth image conversion')
            break
    if os.path.isfile(low_bandwidth_filename):
        try:
            os.remove(image_filename)
        except OSError:
            print('EX: convert_image_to_low_bandwidth unable to delete ' +
                  image_filename)
        os.rename(low_bandwidth_filename, image_filename)
        if os.path.isfile(image_filename):
            print('Image converted to low bandwidth ' + image_filename)
    else:
        print('Low bandwidth converted image not found: ' +
              low_bandwidth_filename)


def process_meta_data(base_dir: str, nickname: str, domain: str,
                      image_filename: str, output_filename: str,
                      city: str, content_license_url: str) -> None:
    """Handles image metadata. This tries to spoof the metadata
    if possible, but otherwise just removes it
    """
    # first remove the metadata
    _remove_meta_data(image_filename, output_filename)

    # now add some spoofed data to misdirect surveillance capitalists
    _spoof_meta_data(base_dir, nickname, domain, output_filename, city,
                     content_license_url)


def _is_media(image_filename: str) -> bool:
    """Is the given file a media file?
    """
    if not os.path.isfile(image_filename):
        print('WARN: Media file does not exist ' + image_filename)
        return False
    permitted_media = get_media_extensions()
    for permit in permitted_media:
        if image_filename.endswith('.' + permit):
            return True
    print('WARN: ' + image_filename + ' is not a permitted media type')
    return False


def create_media_dirs(base_dir: str, media_path: str) -> None:
    """Creates stored media directories
    """
    if not os.path.isdir(base_dir + '/media'):
        os.mkdir(base_dir + '/media')
    if not os.path.isdir(base_dir + '/' + media_path):
        os.mkdir(base_dir + '/' + media_path)


def get_media_path() -> str:
    """Returns the path for stored media
    """
    curr_time = date_utcnow()
    weeks_since_epoch = \
        int((curr_time - date_epoch()).days / 7)
    return 'media/' + str(weeks_since_epoch)


def get_attachment_media_type(filename: str) -> str:
    """Returns the type of media for the given file
    image, video or audio
    """
    media_type = None
    image_types = get_image_extensions()
    for mtype in image_types:
        if filename.endswith('.' + mtype):
            return 'image'
    video_types = get_video_extensions()
    for mtype in video_types:
        if filename.endswith('.' + mtype):
            return 'video'
    audio_types = get_audio_extensions()
    for mtype in audio_types:
        if filename.endswith('.' + mtype):
            return 'audio'
    return media_type


def _update_etag(media_filename: str) -> None:
    """ calculate the etag, which is a sha1 of the data
    """
    # only create etags for media
    if '/media/' not in media_filename:
        return

    # check that the media exists
    if not os.path.isfile(media_filename):
        return

    # read the binary data
    data = None
    try:
        with open(media_filename, 'rb') as fp_media:
            data = fp_media.read()
    except OSError:
        print('EX: _update_etag unable to read ' + str(media_filename))

    if not data:
        return
    # calculate hash
    etag = sha1(data).hexdigest()  # nosec
    # save the hash
    try:
        with open(media_filename + '.etag', 'w+',
                  encoding='utf-8') as fp_media:
            fp_media.write(etag)
    except OSError:
        print('EX: _update_etag unable to write ' +
              str(media_filename) + '.etag')


def _store_video_transcript(video_transcript: str,
                            media_filename: str) -> bool:
    """Stores a video transcript
    """
    video_transcript = video_transcript.strip()
    if not video_transcript.startswith('WEBVTT') or \
       '-->' not in video_transcript or \
       ':' not in video_transcript or \
       '- ' not in video_transcript:
        print('WARN: does not look like a video transcript ' +
              video_transcript)
        return False
    try:
        with open(media_filename + '.vtt', 'w+', encoding='utf-8') as fp_vtt:
            fp_vtt.write(video_transcript)
        return True
    except OSError:
        print('EX: unable to save video transcript ' + media_filename + '.vtt')
    return False


def _log_uploaded_media(base_dir: str, nickname: str, domain: str,
                        media_filename: str) -> None:
    """Creates a log of all media uploaded by an account
    """
    account_dir = acct_dir(base_dir, nickname, domain)
    account_media_log_filename = account_dir + '/media_log.txt'
    media_log = []
    write_type = 'w+'
    if os.path.isfile(account_media_log_filename):
        try:
            with open(account_media_log_filename, 'r',
                      encoding='utf-8') as fp_log:
                media_log = fp_log.read().split('\n')
                write_type = 'a+'
        except OSError:
            print('EX: unable to read media log for ' + nickname)
    # don't include the base directory, in case the installation is later
    # moved around
    if base_dir in media_filename:
        media_filename = media_filename.split(base_dir)[1]
    if media_filename in media_log:
        return
    try:
        with open(account_media_log_filename, write_type,
                  encoding='utf-8') as fp_log:
            fp_log.write(media_filename + '\n')
    except OSError:
        print('EX: unable to write media log for ' + nickname)


def attach_media(base_dir: str, http_prefix: str,
                 nickname: str, domain: str, port: int,
                 post_json: {}, image_filename: str,
                 media_type: str, description: str,
                 video_transcript: str,
                 city: str, low_bandwidth: bool,
                 content_license_url: str,
                 creator: str,
                 system_language: str) -> {}:
    """Attaches media to a json object post
    The description can be None
    """
    if not _is_media(image_filename):
        return post_json

    file_extension = None
    accepted_types = get_media_extensions()
    for mtype in accepted_types:
        if image_filename.endswith('.' + mtype):
            if mtype == 'jpg':
                mtype = 'jpeg'
            if mtype == 'mp3':
                mtype = 'mpeg'
            file_extension = mtype
    if not file_extension:
        return post_json
    media_type = media_type + '/' + file_extension
    print('Attached media type: ' + media_type)

    if file_extension == 'jpeg':
        file_extension = 'jpg'
    if media_type == 'audio/mpeg':
        file_extension = 'mp3'
    if media_type in ('audio/speex', 'audio/x-speex'):
        file_extension = 'spx'

    domain = get_full_domain(domain, port)

    mpath = get_media_path()
    media_path = mpath + '/' + create_password(32) + '.' + file_extension
    media_filename = None
    if base_dir:
        create_media_dirs(base_dir, mpath)
        media_filename = base_dir + '/' + media_path

    media_path = \
        media_path.replace('media/', 'system/media_attachments/files/', 1)
    attachment_json = {
        'mediaType': media_type,
        'name': description,
        'type': 'Document',
        'url': http_prefix + '://' + domain + '/' + media_path
    }
    if content_license_url or creator:
        attachment_json['@context'] = [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1',
            {'schema': 'https://schema.org#'}
        ]
    if content_license_url:
        attachment_json['schema:license'] = content_license_url
        attachment_json['license'] = content_license_url
    if creator:
        attachment_json['schema:creator'] = creator
        attachment_json['attribution'] = [creator]
    if media_type.startswith('image/'):
        attachment_json['blurhash'] = _get_blur_hash()
        # find the dimensions of the image and add them as metadata
        attach_image_width, attach_image_height = \
            get_image_dimensions(image_filename)
        if attach_image_width and attach_image_height:
            attachment_json['width'] = attach_image_width
            attachment_json['height'] = attach_image_height

    # create video transcript
    post_json['attachment'] = [attachment_json]
    if video_transcript and 'video' in media_type:
        if _store_video_transcript(video_transcript, media_filename):
            video_transcript_json = {
                'mediaType': 'text/vtt',
                'name': system_language,
                'type': 'Document',
                'url': http_prefix + '://' + domain + '/' + media_path + '.vtt'
             }
            post_json['attachment'].append(video_transcript_json)

    if base_dir:
        if media_type.startswith('image/'):
            if low_bandwidth:
                convert_image_to_low_bandwidth(image_filename)
            process_meta_data(base_dir, nickname, domain,
                              image_filename, media_filename, city,
                              content_license_url)
        else:
            copyfile(image_filename, media_filename)
        _log_uploaded_media(base_dir, nickname, domain, media_filename)
        _update_etag(media_filename)

    return post_json


def archive_media(base_dir: str, archive_directory: str,
                  max_weeks: int) -> None:
    """Any media older than the given number of weeks gets archived
    """
    if max_weeks == 0:
        return

    curr_time = date_utcnow()
    weeks_since_epoch = int((curr_time - date_epoch()).days/7)
    min_week = weeks_since_epoch - max_weeks

    if archive_directory:
        if not os.path.isdir(archive_directory):
            os.mkdir(archive_directory)
        if not os.path.isdir(archive_directory + '/media'):
            os.mkdir(archive_directory + '/media')

    for _, dirs, _ in os.walk(base_dir + '/media'):
        for week_dir in dirs:
            if int(week_dir) < min_week:
                if archive_directory:
                    move(os.path.join(base_dir + '/media', week_dir),
                         archive_directory + '/media')
                else:
                    # archive to /dev/null
                    rmtree(os.path.join(base_dir + '/media', week_dir),
                           ignore_errors=False, onexc=None)
        break


def path_is_video(path: str) -> bool:
    """Is the given path a video file?
    """
    extensions = get_video_extensions()
    for ext in extensions:
        if path.endswith('.' + ext):
            return True
    return False


def path_is_transcript(path: str) -> bool:
    """Is the given path a video transcript WebVTT file?
    """
    if path.endswith('.vtt'):
        return True
    return False


def path_is_audio(path: str) -> bool:
    """Is the given path an audio file?
    """
    extensions = get_audio_extensions()
    for ext in extensions:
        if path.endswith('.' + ext):
            return True
    return False


def get_image_dimensions(image_filename: str) -> (int, int):
    """Returns the dimensions of an image file
    """
    safe_image_filename = safe_system_string(image_filename)
    try:
        result = subprocess.run(['identify', '-format', '"%wx%h"',
                                 safe_image_filename],
                                stdout=subprocess.PIPE)
    except BaseException:
        print('EX: get_image_dimensions unable to run identify command')
        return None, None
    if not result:
        return None, None
    dimensions_str = result.stdout.decode('utf-8').replace('"', '')
    if 'x' not in dimensions_str:
        return None, None
    width_str = dimensions_str.split('x')[0]
    if not width_str.isdigit():
        return None, None
    height_str = dimensions_str.split('x')[1]
    if not height_str.isdigit():
        return None, None
    return int(width_str), int(height_str)


def apply_watermark_to_image(base_dir: str, nickname: str, domain: str,
                             post_image_filename: str,
                             watermark_width_percent: int,
                             watermark_position: str,
                             watermark_opacity: int) -> bool:
    """Applies a watermark to the given image
    """
    if not os.path.isfile(post_image_filename):
        return False
    if not os.path.isfile('/usr/bin/composite'):
        return False
    watermark_enabled_filename = \
        acct_dir(base_dir, nickname, domain) + '/.watermarkEnabled'
    if not os.path.isfile(watermark_enabled_filename):
        return False
    _, watermark_filename = get_watermark_file(base_dir, nickname, domain)
    if not watermark_filename:
        # does a default watermark filename exist?
        default_watermark_file = base_dir + '/manual/manual-watermark-ai.png'
        if os.path.isfile(default_watermark_file):
            watermark_filename = default_watermark_file
    if not watermark_filename:
        return False
    if not os.path.isfile(watermark_filename):
        return False

    # scale the watermark so that it is a fixed percentage of the image width
    post_image_width, _ = \
        get_image_dimensions(post_image_filename)
    if not post_image_width:
        return False
    watermark_image_width, watermark_image_height = \
        get_image_dimensions(post_image_filename)
    if not watermark_image_width or not watermark_image_height:
        return False
    watermark_width_percent += randint(-5, 5)
    if watermark_width_percent < 0:
        watermark_width_percent = 0
    if watermark_width_percent > 100:
        watermark_width_percent = 100
    scaled_watermark_image_width = \
        int(post_image_width * watermark_width_percent / 100)
    scaled_watermark_image_height = \
        int(watermark_image_height *
            scaled_watermark_image_width / watermark_image_width)

    watermark_position = watermark_position.lower()
    if watermark_position not in ('north', 'south',
                                  'east', 'west',
                                  'northeast', 'northwest',
                                  'southeast', 'southwest',
                                  'random'):
        watermark_position = 'east'

    # choose a random position for the watermark
    if watermark_position == 'random':
        watermark_position = \
            random.choice(['north', 'south',
                           'east', 'west',
                           'northeast', 'northwest',
                           'southeast', 'southwest'])

    watermark_opacity += randint(-5, 5)
    if watermark_opacity < 0:
        watermark_opacity = 0
    if watermark_opacity > 100:
        watermark_opacity = 100

    cmd = \
        '/usr/bin/composite ' + \
        '-geometry ' + str(scaled_watermark_image_width) + 'x' + \
        str(scaled_watermark_image_height) + '+30+5 ' + \
        '-watermark ' + str(watermark_opacity) + '% ' + \
        '-gravity ' + watermark_position + ' ' + \
        safe_system_string(watermark_filename) + ' ' + \
        safe_system_string(post_image_filename) + ' ' + \
        safe_system_string(post_image_filename + '.watermarked')
    subprocess.call(cmd, shell=True)
    if not os.path.isfile(post_image_filename + '.watermarked'):
        return False

    try:
        os.remove(post_image_filename)
    except OSError:
        print('EX: _apply_watermark_to_image unable to remove ' +
              post_image_filename)
        return False

    try:
        os.rename(post_image_filename + '.watermarked', post_image_filename)
    except OSError:
        print('EX: _apply_watermark_to_image unable to rename ' +
              post_image_filename + '.watermarked')
        return False
    return True
