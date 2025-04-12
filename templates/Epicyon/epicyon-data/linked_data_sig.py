__filename__ = "linked_data_sig.py"
__author__ = "Bob Mottram"
__credits__ = ['Based on ' +
               'https://github.com/tsileo/little-boxes']
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Security"

import random
import base64
import hashlib
from cryptography.hazmat.backends import default_backend
from cryptography.hazmat.primitives.serialization import load_pem_private_key
from cryptography.hazmat.primitives.serialization import load_pem_public_key
from cryptography.hazmat.primitives.asymmetric import padding
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import utils as hazutils
from pyjsonld import normalize
from context import has_valid_context
from utils import get_sha_256
from utils import date_utcnow


def _options_hash(doc: {}) -> str:
    """Returns a hash of the signature, with a few fields removed
    """
    doc_sig = dict(doc["signature"])

    # remove fields from signature
    for key in ["type", "id", "signatureValue"]:
        if key in doc_sig:
            del doc_sig[key]

    doc_sig["@context"] = "https://w3id.org/identity/v1"
    options = {
        "algorithm": "URDNA2015",
        "format": "application/nquads"
    }

    normalized = normalize(doc_sig, options)
    hsh = hashlib.new("sha256")
    hsh.update(normalized.encode("utf-8"))
    return hsh.hexdigest()


def _doc_hash(doc: {}) -> str:
    """Returns a hash of the ActivityPub post
    """
    doc = dict(doc)

    # remove the signature
    if "signature" in doc:
        del doc["signature"]

    options = {
        "algorithm": "URDNA2015",
        "format": "application/nquads"
    }

    normalized = normalize(doc, options)
    hsh = hashlib.new("sha256")
    hsh.update(normalized.encode("utf-8"))
    return hsh.hexdigest()


def verify_json_signature(doc: {}, public_key_pem: str) -> bool:
    """Returns True if the given ActivityPub post was sent
    by an actor having the given public key
    """
    if not has_valid_context(doc):
        return False
    pubkey = load_pem_public_key(public_key_pem.encode('utf-8'),
                                 backend=default_backend())
    to_be_signed = _options_hash(doc) + _doc_hash(doc)
    signature = doc["signature"]["signatureValue"]

    digest = get_sha_256(to_be_signed.encode("utf-8"))
    base64sig = base64.b64decode(signature)

    try:
        pubkey.verify(
            base64sig,
            digest,
            padding.PKCS1v15(),
            hazutils.Prehashed(hashes.SHA256()))
        return True
    except BaseException as ex:
        print('EX: verify_json_signature unable to verify ' + str(ex))
        return False


def generate_json_signature(doc: {}, private_key_pem: str,
                            debug: bool) -> None:
    """Adds a json signature to the given ActivityPub post
    """
    if not doc.get('actor'):
        if debug:
            print('DEBUG: generate_json_signature does not have an actor')
        return
    if not has_valid_context(doc):
        if debug:
            print('DEBUG: generate_json_signature does not have valid context')
        return
    options = {
        "type": "RsaSignature2017",
        "nonce": '%030x' % random.randrange(16**64),
        "creator": doc["actor"] + "#main-key",
        "created": date_utcnow().replace(microsecond=0).isoformat() + "Z",
    }
    doc["signature"] = options
    to_be_signed = _options_hash(doc) + _doc_hash(doc)

    key = load_pem_private_key(private_key_pem.encode('utf-8'),
                               None, backend=default_backend())
    if debug:
        print('DEBUG: generate_json_signature get_sha_256')
    digest = get_sha_256(to_be_signed.encode("utf-8"))
    if debug:
        print('DEBUG: generate_json_signature key.sign')
    signature = key.sign(digest,
                         padding.PKCS1v15(),
                         hazutils.Prehashed(hashes.SHA256()))
    if debug:
        print('DEBUG: generate_json_signature base64.b64encode')
    sig = base64.b64encode(signature)
    options["signatureValue"] = sig.decode("utf-8")
