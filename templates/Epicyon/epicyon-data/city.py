""" Decoy location metadata on images.
An aim of this is to reinforce confirmation bias within machine learning
systems looking for patterns.
"""

__filename__ = "city.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Metadata"

import os
import datetime
import random
import math
from random import randint
from utils import acct_dir
from utils import remove_eol

# states which the simulated city dweller can be in
PERSON_SLEEP = 0
PERSON_WORK = 1
PERSON_PLAY = 2
PERSON_SHOP = 3
PERSON_EVENING = 4
PERSON_PARTY = 5

BUSY_STATES = (PERSON_WORK, PERSON_SHOP, PERSON_PLAY, PERSON_PARTY)


def _get_decoy_camera(decoy_seed: int) -> (str, str, int):
    """Returns a decoy camera make and model which took the photo
    """
    cameras = [
        ["Apple", "iPhone SE"],
        ["Apple", "iPhone XR"],
        ["Apple", "iPhone 8"],
        ["Apple", "iPhone 11"],
        ["Apple", "iPhone 11 Pro"],
        ["Apple", "iPhone 12"],
        ["Apple", "iPhone 12 Mini"],
        ["Apple", "iPhone 12 Pro Max"],
        ["Apple", "iPhone 13"],
        ["Apple", "iPhone 13 Mini"],
        ["Apple", "iPhone 13 Pro"],
        ["Apple", "iPhone 14"],
        ["Apple", "iPhone 14 Pro"],
        ["Apple", "iPhone 15"],
        ["Apple", "iPhone 15 Pro"],
        ["Samsung", "Galaxy S24 Ultra"],
        ["Samsung", "Galaxy S24 Plus"],
        ["Samsung", "Galaxy S24"],
        ["Samsung", "Galaxy S23 Plus"],
        ["Samsung", "Galaxy S23"],
        ["Samsung", "Galaxy S22 Plus"],
        ["Samsung", "Galaxy S22"],
        ["Samsung", "Galaxy S21 Ultra"],
        ["Samsung", "Galaxy S21"],
        ["Samsung", "Galaxy Note 20 Ultra"],
        ["Samsung", "Galaxy S20 Plus"],
        ["Samsung", "Galaxy S20 FE 5G"],
        ["Samsung", "Galaxy Z FOLD 2"],
        ["Samsung", "Galaxy S12 Plus"],
        ["Samsung", "Galaxy S12"],
        ["Samsung", "Galaxy S11 Plus"],
        ["Samsung", "Galaxy Z Flip"],
        ["Samsung", "Galaxy A54"],
        ["Samsung", "Galaxy A51"],
        ["Samsung", "Galaxy A60"],
        ["Samsung", "Note 13"],
        ["Samsung", "Note 13 Plus"],
        ["Samsung", "Note 12"],
        ["Samsung", "Note 12 Plus"],
        ["Samsung", "Note 11"],
        ["Samsung", "Note 11 Plus"],
        ["Samsung", "Note 10"],
        ["Samsung", "Note 10 Plus"],
        ["Samsung", "Galaxy Note 20 Ultra"],
        ["Samsung", "Galaxy S20 FE"],
        ["Samsung", "Galaxy Z Fold 2"],
        ["Samsung", "Galaxy A52 5G"],
        ["Samsung", "Galaxy A71 5G"],
        ["Google", "Pixel 8 Pro"],
        ["Google", "Pixel 8a"],
        ["Google", "Pixel 8"],
        ["Google", "Pixel 7 Pro"],
        ["Google", "Pixel 7"],
        ["Google", "Pixel 6 Pro"],
        ["Google", "Pixel 6"],
        ["Google", "Pixel 5"],
        ["Google", "Pixel 4a"],
        ["Google", "Pixel 4 XL"],
        ["Google", "Pixel 3 XL"],
        ["Google", "Pixel 4"],
        ["Google", "Pixel 4a 5G"],
        ["Google", "Pixel 3"],
        ["Google", "Pixel 3a"]
    ]
    randgen = random.Random(decoy_seed)
    index = randgen.randint(0, len(cameras) - 1)
    serial_number = randgen.randint(100000000000, 999999999999999999999999)
    return cameras[index][0], cameras[index][1], serial_number


def _get_city_pulse(curr_time_of_day, decoy_seed: int) -> (float, float):
    """This simulates expected average patterns of movement in a city.
    Jane or Joe average lives and works in the city, commuting in
    and out of the central district for work. They have a unique
    life pattern, which machine learning can latch onto.
    This returns a polar coordinate for the simulated city dweller:
    Distance from the city centre is in the range 0.0 - 1.0
    Angle is in radians
    """
    randgen = random.Random(decoy_seed)
    variance = 3
    data_decoy_state = PERSON_SLEEP
    weekday = curr_time_of_day.weekday()
    min_hour = 7 + randint(0, variance)
    max_hour = 17 + randint(0, variance)
    if curr_time_of_day.hour > min_hour:
        if curr_time_of_day.hour <= max_hour:
            if weekday < 5:
                data_decoy_state = PERSON_WORK
            elif weekday == 5:
                data_decoy_state = PERSON_SHOP
            else:
                data_decoy_state = PERSON_PLAY
        else:
            if weekday < 5:
                data_decoy_state = PERSON_EVENING
            else:
                data_decoy_state = PERSON_PARTY
    randgen2 = random.Random(decoy_seed + data_decoy_state)
    angle_radians = \
        (randgen2.randint(0, 100000) / 100000) * 2 * math.pi
    # some people are quite random, others have more predictable habits
    decoy_randomness = randgen.randint(1, 3)
    # occasionally throw in a wildcard to keep the machine learning guessing
    if randint(0, 100) < decoy_randomness:
        distance_from_city_center = randint(0, 100000) / 100000
        angle_radians = (randint(0, 100000) / 100000) * 2 * math.pi
    else:
        # what consitutes the central district is fuzzy
        central_district_fuzz = (randgen.randint(0, 100000) / 100000) * 0.1
        busy_radius = 0.3 + central_district_fuzz
        if data_decoy_state in BUSY_STATES:
            # if we are busy then we're somewhere in the city center
            distance_from_city_center = \
                (randgen.randint(0, 100000) / 100000) * busy_radius
        else:
            # otherwise we're in the burbs
            distance_from_city_center = busy_radius + \
                ((1.0 - busy_radius) * (randgen.randint(0, 100000) / 100000))
    return distance_from_city_center, angle_radians


def parse_nogo_string(nogo_line: str) -> []:
    """Parses a line from locations_nogo.txt and returns the polygon
    """
    nogo_line = remove_eol(nogo_line)
    polygon_str = nogo_line.split(':', 1)[1]
    if ';' in polygon_str:
        pts = polygon_str.split(';')
    else:
        pts = polygon_str.split(',')
    if len(pts) <= 4:
        return []
    polygon: list[list] = []
    for index in range(int(len(pts)/2)):
        if index*2 + 1 >= len(pts):
            break
        longitude_str = pts[index*2].strip()
        latitude_str = pts[index*2 + 1].strip()
        if 'E' in latitude_str or 'W' in latitude_str:
            longitude_str = pts[index*2 + 1].strip()
            latitude_str = pts[index*2].strip()
        if 'E' in longitude_str:
            longitude_str = \
                longitude_str.replace('E', '')
            longitude = float(longitude_str)
        elif 'W' in longitude_str:
            longitude_str = \
                longitude_str.replace('W', '')
            longitude = -float(longitude_str)
        else:
            longitude = float(longitude_str)
        latitude = float(latitude_str)
        polygon.append([latitude, longitude])
    return polygon


def spoof_geolocation(base_dir: str,
                      city: str, curr_time, decoy_seed: int,
                      cities_list: [],
                      nogo_list: []) -> (float, float, str, str,
                                         str, str, int):
    """Given a city and the current time spoofs the location
    for an image
    returns latitude, longitude, N/S, E/W,
    camera make, camera model, camera serial number
    """
    locations_filename = base_dir + '/custom_locations.txt'
    if not os.path.isfile(locations_filename):
        locations_filename = base_dir + '/locations.txt'

    nogo_filename = base_dir + '/custom_locations_nogo.txt'
    if not os.path.isfile(nogo_filename):
        nogo_filename = base_dir + '/locations_nogo.txt'

    man_city_radius = 0.1
    variance_at_location = 0.0004
    default_latitude = 51.8744
    default_longitude = 0.368333
    default_latdirection = 'N'
    default_longdirection = 'W'

    if cities_list:
        cities = cities_list
    else:
        if not os.path.isfile(locations_filename):
            return (default_latitude, default_longitude,
                    default_latdirection, default_longdirection,
                    "", "", 0)
        cities: list[str] = []
        try:
            with open(locations_filename, 'r', encoding='utf-8') as fp_loc:
                cities = fp_loc.readlines()
        except OSError:
            print('EX: unable to read locations ' + locations_filename)

    nogo = []
    if nogo_list:
        nogo = nogo_list
    else:
        if os.path.isfile(nogo_filename):
            nogo_list: list[str] = []
            try:
                with open(nogo_filename, 'r', encoding='utf-8') as fp_nogo:
                    nogo_list = fp_nogo.readlines()
            except OSError:
                print('EX: spoof_geolocation unable to read ' + nogo_filename)
            for line in nogo_list:
                if line.startswith(city + ':'):
                    polygon = parse_nogo_string(line)
                    if polygon:
                        nogo.append(polygon)

    city = city.lower()
    for city_name in cities:
        if city in city_name.lower():
            city_fields = city_name.split(':')
            latitude = city_fields[1]
            longitude = city_fields[2]
            area_km2 = 0
            if len(city_fields) > 3:
                area_km2 = int(city_fields[3])
            latdirection = 'N'
            longdirection = 'E'
            if 'S' in latitude:
                latdirection = 'S'
                latitude = latitude.replace('S', '')
            if 'W' in longitude:
                longdirection = 'W'
                longitude = longitude.replace('W', '')
            latitude = float(latitude)
            longitude = float(longitude)
            # get the time of day at the city
            approx_time_zone = int(longitude / 15.0)
            if longdirection == 'E':
                approx_time_zone = -approx_time_zone
            curr_time_adjusted = curr_time - \
                datetime.timedelta(hours=approx_time_zone)
            cam_make, cam_model, cam_serial_number = \
                _get_decoy_camera(decoy_seed)
            valid_coord = False
            seed_offset = 0
            while not valid_coord:
                # patterns of activity change in the city over time
                (distance_from_city_center, angle_radians) = \
                    _get_city_pulse(curr_time_adjusted,
                                    decoy_seed + seed_offset)
                # The city radius value is in longitude and the reference
                # is Manchester. Adjust for the radius of the chosen city.
                if area_km2 > 1:
                    man_radius = math.sqrt(1276 / math.pi)
                    radius = math.sqrt(area_km2 / math.pi)
                    city_radius_deg = (radius / man_radius) * man_city_radius
                else:
                    city_radius_deg = man_city_radius
                # Get the position within the city, with some randomness added
                latitude += \
                    distance_from_city_center * city_radius_deg * \
                    math.cos(angle_radians)
                longitude += \
                    distance_from_city_center * city_radius_deg * \
                    math.sin(angle_radians)
                longval = longitude
                if longdirection == 'W':
                    longval = -longitude
                valid_coord = not point_in_nogo(nogo, latitude, longval)
                if not valid_coord:
                    seed_offset += 1
                    if seed_offset > 100:
                        break
            # add a small amount of variance around the location
            fraction = randint(0, 100000) / 100000
            distance_from_location = fraction * fraction * variance_at_location
            fraction = randint(0, 100000) / 100000
            angle_from_location = fraction * 2 * math.pi
            latitude += distance_from_location * math.cos(angle_from_location)
            longitude += distance_from_location * math.sin(angle_from_location)

            # gps locations aren't transcendental, so round to a fixed
            # number of decimal places
            latitude = int(latitude * 100000) / 100000.0
            longitude = int(longitude * 100000) / 100000.0
            return (latitude, longitude, latdirection, longdirection,
                    cam_make, cam_model, cam_serial_number)

    return (default_latitude, default_longitude,
            default_latdirection, default_longdirection,
            "", "", 0)


def get_spoofed_city(city: str, base_dir: str,
                     nickname: str, domain: str) -> str:
    """Returns the name of the city to use as a GPS spoofing location for
    image metadata
    """
    city = ''
    city_filename = acct_dir(base_dir, nickname, domain) + '/city.txt'
    if os.path.isfile(city_filename):
        try:
            with open(city_filename, 'r', encoding='utf-8') as fp_city:
                city1 = fp_city.read()
                city = remove_eol(city1)
        except OSError:
            print('EX: get_spoofed_city unable to read ' + city_filename)
    return city


def _point_in_polygon(poly: [], x_coord: float, y_coord: float) -> bool:
    """Returns true if the given point is inside the given polygon
    """
    num = len(poly)
    inside = False
    p2x = 0.0
    p2y = 0.0
    xints = 0.0
    p1x, p1y = poly[0]
    for i in range(num + 1):
        p2x, p2y = poly[i % num]
        if y_coord > min(p1y, p2y):
            if y_coord <= max(p1y, p2y):
                if x_coord <= max(p1x, p2x):
                    if p1y != p2y:
                        xints = \
                            (y_coord - p1y) * (p2x - p1x) / (p2y - p1y) + p1x
                    if p1x == p2x or x_coord <= xints:
                        inside = not inside
        p1x, p1y = p2x, p2y

    return inside


def point_in_nogo(nogo: [], latitude: float, longitude: float) -> bool:
    """Returns true of the given geolocation is within a nogo area
    """
    for polygon in nogo:
        if _point_in_polygon(polygon, latitude, longitude):
            return True
    return False
