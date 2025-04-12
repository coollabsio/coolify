__filename__ = "qrcode.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Core"

import pyqrcode
from utils import data_dir


def save_domain_qrcode(base_dir: str, http_prefix: str,
                       domain_full: str, scale: int) -> None:
    """Saves a qrcode image for the domain name
    This helps to transfer onion or i2p domains to a mobile device
    """
    qrcode_filename = data_dir(base_dir) + '/qrcode.png'
    url = pyqrcode.create(http_prefix + '://' + domain_full)
    url.png(qrcode_filename, scale)
