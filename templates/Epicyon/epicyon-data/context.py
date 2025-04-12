__filename__ = "inbox.py"
__author__ = "Bob Mottram"
__license__ = "AGPL3+"
__version__ = "1.6.0"
__maintainer__ = "Bob Mottram"
__email__ = "bob@libreserver.org"
__status__ = "Production"
__module_group__ = "Security"


VALID_CONTEXTS = (
    "https://www.w3.org/ns/activitystreams",
    "https://w3id.org/identity/v1",
    "https://w3id.org/security/v1",
    "*/apschema/v1.9",
    "*/apschema/v1.10",
    "*/apschema/v1.21",
    "*/apschema/v1.20",
    "*/litepub-0.1.jsonld",
    "https://litepub.social/litepub/context.jsonld",
    "*/socialweb/webfinger",
    "*/socialweb/webfinger.jsonld",
    "https://www.w3.org/ns/did/v1",
    "https://w3id.org/security/multikey/v1",
    "*/vc-data-integrity/contexts/multikey/v1.jsonld",
    "https://w3id.org/security/data-integrity/v1",
    "*/contexts/data-integrity/v1.jsonld",
    "*/ns/privacyHeaders"
)


def get_individual_post_context() -> []:
    """Returns the context for an individual post
    https://codeberg.org/fediverse/fep/src/branch/main/fep/76ea/fep-76ea.md
    """
    return [
        'https://www.w3.org/ns/activitystreams',
        {
            "ostatus": "http://ostatus.org#",
            "atomUri": "ostatus:atomUri",
            "inReplyToAtomUri": "ostatus:inReplyToAtomUri",
            "conversation": "ostatus:conversation",
            "sensitive": "as:sensitive",
            "toot": "http://joinmastodon.org/ns#",
            "votersCount": "toot:votersCount",
            "blurhash": "toot:blurhash",
            "thr": "https://purl.archive.org/socialweb/thread#",
            "thread": {
                "@id": "thr:thread",
                "@type": "@id"
            },
            "root": {
                "@id": "thr:root",
                "@type": "@id"
            }
        }
    ]


def _has_valid_context_list(post_json_object: {}) -> bool:
    """Are the links within the @context of a post recognised?
    """
    for url in post_json_object['@context']:
        if not isinstance(url, str):
            continue
        if url in VALID_CONTEXTS:
            continue
        # is this a wildcard context?
        wildcard_found = False
        for cont in VALID_CONTEXTS:
            if cont.startswith('*'):
                cont = cont.replace('*', '')
                if url.endswith(cont):
                    wildcard_found = True
                    break
        if not wildcard_found:
            print('Unrecognized @context 1: ' + url)
            return False
    return True


def _has_valid_context_str(post_json_object: {}) -> bool:
    """Are the links within the @context of a post recognised?
    """
    url = post_json_object['@context']
    if url not in VALID_CONTEXTS:
        wildcard_found = False
        for cont in VALID_CONTEXTS:
            if cont.startswith('*'):
                cont = cont.replace('*', '')
                if url.endswith(cont):
                    wildcard_found = True
                    break
        if not wildcard_found:
            print('Unrecognized @context 2: ' + url)
            return False
    return True


def has_valid_context(post_json_object: {}) -> bool:
    """Are the links within the @context of a post recognised?
    """
    if not post_json_object.get('@context'):
        return False
    if isinstance(post_json_object['@context'], list):
        return _has_valid_context_list(post_json_object)
    if isinstance(post_json_object['@context'], str):
        return _has_valid_context_str(post_json_object)
    # not a list or string
    return False


def get_data_integrity_v1_schema() -> {}:
    """ https://w3id.org/security/data-integrity/v1
    */contexts/data-integrity/v1.jsonld
    """
    proof_purpose_json = {
        "@id": "https://w3id.org/security#proofPurpose",
        "@type": "@vocab",
        "@context": {
            "@protected": True,
            "id": "@id",
            "type": "@type",
            "assertionMethod": {
                "@id": "https://w3id.org/security#assertionMethod",
                "@type": "@id",
                "@container": "@set"
            },
            "authentication": {
                "@id": "https://w3id.org/security#authenticationMethod",
                "@type": "@id",
                "@container": "@set"
            },
            "capabilityInvocation": {
                "@id": "https://w3id.org/security#capabilityInvocationMethod",
                "@type": "@id",
                "@container": "@set"
            },
            "capabilityDelegation": {
                "@id": "https://w3id.org/security#capabilityDelegationMethod",
                "@type": "@id",
                "@container": "@set"
            },
            "keyAgreement": {
                "@id": "https://w3id.org/security#keyAgreementMethod",
                "@type": "@id",
                "@container": "@set"
            }
        }
    }
    return {
        "@context": {
            "id": "@id",
            "type": "@type",
            "@protected": True,
            "proof": {
                "@id": "https://w3id.org/security#proof",
                "@type": "@id",
                "@container": "@graph"
            },
            "DataIntegrityProof": {
                "@id": "https://w3id.org/security#DataIntegrityProof",
                "@context": {
                    "@protected": True,
                    "id": "@id",
                    "type": "@type",
                    "challenge": "https://w3id.org/security#challenge",
                    "created": {
                        "@id": "http://purl.org/dc/terms/created",
                        "@type": "http://www.w3.org/2001/XMLSchema#dateTime"
                    },
                    "domain": "https://w3id.org/security#domain",
                    "expires": {
                        "@id": "https://w3id.org/security#expiration",
                        "@type": "http://www.w3.org/2001/XMLSchema#dateTime"
                    },
                    "nonce": "https://w3id.org/security#nonce",
                    "proofPurpose": proof_purpose_json,
                    "cryptosuite": "https://w3id.org/security#cryptosuite",
                    "proofValue": {
                        "@id": "https://w3id.org/security#proofValue",
                        "@type": "https://w3id.org/security#multibase"
                    },
                    "verificationMethod": {
                        "@id": "https://w3id.org/security#verificationMethod",
                        "@type": "@id"
                    }
                }
            }
        }
    }


def get_multikey_v1_schema() -> {}:
    """ https://w3id.org/security/multikey/v1
    https://w3c.github.io/vc-data-integrity/contexts/multikey/v1.jsonld
    """
    return {
        "@context": {
            "id": "@id",
            "type": "@type",
            "@protected": True,
            "Multikey": {
                "@id": "https://w3id.org/security#Multikey",
                "@context": {
                    "@protected": True,
                    "id": "@id",
                    "type": "@type",
                    "controller": {
                        "@id": "https://w3id.org/security#controller",
                        "@type": "@id"
                    },
                    "revoked": {
                        "@id": "https://w3id.org/security#revoked",
                        "@type": "http://www.w3.org/2001/XMLSchema#dateTime"
                    },
                    "expires": {
                        "@id": "https://w3id.org/security#expiration",
                        "@type": "http://www.w3.org/2001/XMLSchema#dateTime"
                    },
                    "publicKeyMultibase": {
                        "@id": "https://w3id.org/security#publicKeyMultibase",
                        "@type": "https://w3id.org/security#multibase"
                    },
                    "secretKeyMultibase": {
                        "@id": "https://w3id.org/security#secretKeyMultibase",
                        "@type": "https://w3id.org/security#multibase"
                    }
                }
            }
        }
    }


def get_did_v1_schema() -> {}:
    # https://www.w3.org/ns/did/v1
    return {
        "@context": {
            "@protected": True,
            "id": "@id",
            "type": "@type",
            "alsoKnownAs": {
                "@id": "https://www.w3.org/ns/activitystreams#alsoKnownAs",
                "@type": "@id"
            },
            "assertionMethod": {
                "@id": "https://w3id.org/security#assertionMethod",
                "@type": "@id",
                "@container": "@set"
            },
            "authentication": {
                "@id": "https://w3id.org/security#authenticationMethod",
                "@type": "@id",
                "@container": "@set"
            },
            "capabilityDelegation": {
                "@id": "https://w3id.org/security#capabilityDelegationMethod",
                "@type": "@id",
                "@container": "@set"
            },
            "capabilityInvocation": {
                "@id": "https://w3id.org/security#capabilityInvocationMethod",
                "@type": "@id",
                "@container": "@set"
            },
            "controller": {
                "@id": "https://w3id.org/security#controller",
                "@type": "@id"
            },
            "keyAgreement": {
                "@id": "https://w3id.org/security#keyAgreementMethod",
                "@type": "@id",
                "@container": "@set"
            },
            "service": {
                "@id": "https://www.w3.org/ns/did#service",
                "@type": "@id",
                "@context": {
                    "@protected": True,
                    "id": "@id",
                    "type": "@type",
                    "serviceEndpoint": {
                        "@id": "https://www.w3.org/ns/did#serviceEndpoint",
                        "@type": "@id"
                    }
                }
            },
            "verificationMethod": {
                "@id": "https://w3id.org/security#verificationMethod",
                "@type": "@id"
            }
        }
    }


def getApschemaV1_9() -> {}:
    # https://domain/apschema/v1.9
    return {
        "@context": {
            "zot": "https://hub.disroot.org/apschema#",
            "id": "@id",
            "type": "@type",
            "commentPolicy": "as:commentPolicy",
            "meData": "zot:meData",
            "meDataType": "zot:meDataType",
            "meEncoding": "zot:meEncoding",
            "meAlgorithm": "zot:meAlgorithm",
            "meCreator": "zot:meCreator",
            "meSignatureValue": "zot:meSignatureValue",
            "locationAddress": "zot:locationAddress",
            "locationPrimary": "zot:locationPrimary",
            "locationDeleted": "zot:locationDeleted",
            "nomadicLocation": "zot:nomadicLocation",
            "nomadicHubs": "zot:nomadicHubs",
            "emojiReaction": "zot:emojiReaction",
            "expires": "zot:expires",
            "directMessage": "zot:directMessage",
            "schema": "http://schema.org#",
            "PropertyValue": "schema:PropertyValue",
            "value": "schema:value",
            "magicEnv": {
                "@id": "zot:magicEnv",
                "@type": "@id"
            },
            "nomadicLocations": {
                "@id": "zot:nomadicLocations",
                "@type": "@id"
            },
            "ostatus": "http://ostatus.org#",
            "conversation": "ostatus:conversation",
            "diaspora": "https://diasporafoundation.org/ns/",
            "guid": "diaspora:guid",
            "Hashtag": "as:Hashtag"
        }
    }


def getApschemaV1_20() -> {}:
    # https://domain/apschema/v1.20
    return {
        "@context":
        {
            "as": "https://www.w3.org/ns/activitystreams#",
            "zot": "https://zap.dog/apschema#",
            "toot": "http://joinmastodon.org/ns#",
            "ostatus": "http://ostatus.org#",
            "schema": "http://schema.org#",
            "litepub": "http://litepub.social/ns#",
            "sm": "http://smithereen.software/ns#",
            "conversation": "ostatus:conversation",
            "manuallyApprovesFollowers": "as:manuallyApprovesFollowers",
            "oauthRegistrationEndpoint": "litepub:oauthRegistrationEndpoint",
            "sensitive": "as:sensitive",
            "movedTo": "as:movedTo",
            "copiedTo": "as:copiedTo",
            "alsoKnownAs": "as:alsoKnownAs",
            "inheritPrivacy": "as:inheritPrivacy",
            "EmojiReact": "as:EmojiReact",
            "commentPolicy": "zot:commentPolicy",
            "topicalCollection": "zot:topicalCollection",
            "eventRepeat": "zot:eventRepeat",
            "emojiReaction": "zot:emojiReaction",
            "expires": "zot:expires",
            "directMessage": "zot:directMessage",
            "Category": "zot:Category",
            "replyTo": "zot:replyTo",
            "PropertyValue": "schema:PropertyValue",
            "value": "schema:value",
            "discoverable": "toot:discoverable",
            "wall": "sm:wall"
        }
    }


def get_webfinger_schema() -> {}:
    # https://domain/socialweb/webfinger.jsonld
    # https://domain/socialweb/webfinger
    return {
        "@context": {
            "wf": "https://purl.archive.org/socialweb/webfinger#",
            "xsd": "http://www.w3.org/2001/XMLSchema#",
            "webfinger": {
                "@id": "wf:webfinger",
                "@type": "xsd:string"
            }
        }
    }


def getApschemaV1_10() -> {}:
    # https://domain/apschema/v1.10
    return {
        '@context': {
            'Hashtag': 'as:Hashtag',
            'PropertyValue': 'schema:PropertyValue',
            'commentPolicy': 'zot:commentPolicy',
            'conversation': 'ostatus:conversation',
            'diaspora': 'https://diasporafoundation.org/ns/',
            'directMessage': 'zot:directMessage',
            'emojiReaction': 'zot:emojiReaction',
            'expires': 'zot:expires',
            'guid': 'diaspora:guid',
            'id': '@id',
            'locationAddress': 'zot:locationAddress',
            'locationDeleted': 'zot:locationDeleted',
            'locationPrimary': 'zot:locationPrimary',
            'magicEnv': {'@id': 'zot:magicEnv', '@type': '@id'},
            'manuallyApprovesFollowers': 'as:manuallyApprovesFollowers',
            'meAlgorithm': 'zot:meAlgorithm',
            'meCreator': 'zot:meCreator',
            'meData': 'zot:meData',
            'meDataType': 'zot:meDataType',
            'meEncoding': 'zot:meEncoding',
            'meSignatureValue': 'zot:meSignatureValue',
            'nomadicHubs': 'zot:nomadicHubs',
            'nomadicLocation': 'zot:nomadicLocation',
            'nomadicLocations': {'@id': 'zot:nomadicLocations',
                                 '@type': '@id'},
            'ostatus': 'http://ostatus.org#',
            'schema': 'http://schema.org#',
            'type': '@type',
            'value': 'schema:value',
            'zot': 'https://hubzilla.vikshepa.com/apschema#'
        }
    }


def getApschemaV1_21() -> {}:
    # https://domain/apschema/v1.21
    return {
        "@context": {
            "zot": "https://raitisoja.com/apschema#",
            "as": "https://www.w3.org/ns/activitystreams#",
            "toot": "http://joinmastodon.org/ns#",
            "ostatus": "http://ostatus.org#",
            "schema": "http://schema.org#",
            "conversation": "ostatus:conversation",
            "sensitive": "as:sensitive",
            "movedTo": "as:movedTo",
            "copiedTo": "as:copiedTo",
            "alsoKnownAs": "as:alsoKnownAs",
            "inheritPrivacy": "as:inheritPrivacy",
            "EmojiReact": "as:EmojiReact",
            "commentPolicy": "zot:commentPolicy",
            "topicalCollection": "zot:topicalCollection",
            "eventRepeat": "zot:eventRepeat",
            "emojiReaction": "zot:emojiReaction",
            "expires": "zot:expires",
            "directMessage": "zot:directMessage",
            "Category": "zot:Category",
            "replyTo": "zot:replyTo",
            "PropertyValue": "schema:PropertyValue",
            "value": "schema:value",
            "discoverable": "toot:discoverable"
        }
    }


def get_litepub_social() -> {}:
    # https://litepub.social/litepub/context.jsonld
    return {
        '@context': [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1',
            {
                'Emoji': 'toot:Emoji',
                'Hashtag': 'as:Hashtag',
                'PropertyValue': 'schema:PropertyValue',
                'atomUri': 'ostatus:atomUri',
                'conversation': {
                    '@id': 'ostatus:conversation',
                    '@type': '@id'
                },
                'manuallyApprovesFollowers': 'as:manuallyApprovesFollowers',
                'ostatus': 'http://ostatus.org#',
                'schema': 'http://schema.org',
                'sensitive': 'as:sensitive',
                'toot': 'http://joinmastodon.org/ns#',
                'totalItems': 'as:totalItems',
                'value': 'schema:value'
            }
        ]
    }


def getLitepubV0_1() -> {}:
    # https://domain/schemas/litepub-0.1.jsonld
    return {
        '@context': [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1',
            {
                'ChatMessage': 'litepub:ChatMessage',
                'Emoji': 'toot:Emoji',
                'EmojiReact': 'litepub:EmojiReact',
                'Hashtag': 'as:Hashtag',
                'PropertyValue': 'schema:PropertyValue',
                'alsoKnownAs': {
                    '@id': 'as:alsoKnownAs',
                    '@type': '@id'
                },
                'atomUri': 'ostatus:atomUri',
                'capabilities': 'litepub:capabilities',
                'contentMap': {
                    '@container': '@language',
                    '@id': 'as:content'
                },
                'conversation': {
                    '@id': 'ostatus:conversation',
                    '@type': '@id'
                },
                'directMessage': 'litepub:directMessage',
                'discoverable': 'toot:discoverable',
                'fedibird': 'http://fedibird.com/ns#',
                'formerRepresentations': 'litepub:formerRepresentations',
                'invisible': 'litepub:invisible',
                'listMessage': {
                    '@id': 'litepub:listMessage',
                    '@type': '@id'
                },
                'litepub': 'http://litepub.social/ns#',
                'manuallyApprovesFollowers': 'as:manuallyApprovesFollowers',
                'misskey': 'https://misskey-hub.net/ns#',
                'oauthRegistrationEndpoint': {
                    '@id': 'litepub:oauthRegistrationEndpoint',
                    '@type': '@id'
                },
                'ostatus': 'http://ostatus.org#',
                'quoteUri': 'fedibird:quoteUri',
                'quoteUrl': 'as:quoteUrl',
                'schema': 'http://schema.org#',
                'sensitive': 'as:sensitive',
                'toot': 'http://joinmastodon.org/ns#',
                'value': 'schema:value',
                'vcard': 'http://www.w3.org/2006/vcard/ns#'}
        ]
    }


def get_v1security_schema() -> {}:
    # https://w3id.org/security/v1
    return {
        "@context": {
            "id": "@id",
            "type": "@type",
            "dc": "http://purl.org/dc/terms/",
            "sec": "https://w3id.org/security#",
            "xsd": "http://www.w3.org/2001/XMLSchema#",
            "EcdsaKoblitzSignature2016": "sec:EcdsaKoblitzSignature2016",
            "Ed25519Signature2018": "sec:Ed25519Signature2018",
            "EncryptedMessage": "sec:EncryptedMessage",
            "GraphSignature2012": "sec:GraphSignature2012",
            "LinkedDataSignature2015": "sec:LinkedDataSignature2015",
            "LinkedDataSignature2016": "sec:LinkedDataSignature2016",
            "CryptographicKey": "sec:Key",
            "authenticationTag": "sec:authenticationTag",
            "canonicalizationAlgorithm": "sec:canonicalizationAlgorithm",
            "cipherAlgorithm": "sec:cipherAlgorithm",
            "cipherData": "sec:cipherData",
            "cipherKey": "sec:cipherKey",
            "created": {"@id": "dc:created", "@type": "xsd:dateTime"},
            "creator": {"@id": "dc:creator", "@type": "@id"},
            "digestAlgorithm": "sec:digestAlgorithm",
            "digestValue": "sec:digestValue",
            "domain": "sec:domain",
            "encryptionKey": "sec:encryptionKey",
            "expiration": {"@id": "sec:expiration", "@type": "xsd:dateTime"},
            "expires": {"@id": "sec:expiration", "@type": "xsd:dateTime"},
            "initializationVector": "sec:initializationVector",
            "iterationCount": "sec:iterationCount",
            "nonce": "sec:nonce",
            "normalizationAlgorithm": "sec:normalizationAlgorithm",
            "owner": {"@id": "sec:owner", "@type": "@id"},
            "password": "sec:password",
            "privateKey": {"@id": "sec:privateKey", "@type": "@id"},
            "privateKeyPem": "sec:privateKeyPem",
            "publicKey": {"@id": "sec:publicKey", "@type": "@id"},
            "publicKeyBase58": "sec:publicKeyBase58",
            "publicKeyPem": "sec:publicKeyPem",
            "publicKeyWif": "sec:publicKeyWif",
            "publicKeyService": {
                "@id": "sec:publicKeyService", "@type": "@id"
            },
            "revoked": {"@id": "sec:revoked", "@type": "xsd:dateTime"},
            "salt": "sec:salt",
            "signature": "sec:signature",
            "signatureAlgorithm": "sec:signingAlgorithm",
            "signatureValue": "sec:signatureValue"
        }
    }


def get_v1schema() -> {}:
    # https://w3id.org/identity/v1
    return {
        "@context": {
            "id": "@id",
            "type": "@type",
            "cred": "https://w3id.org/credentials#",
            "dc": "http://purl.org/dc/terms/",
            "identity": "https://w3id.org/identity#",
            "perm": "https://w3id.org/permissions#",
            "ps": "https://w3id.org/payswarm#",
            "rdf": "http://www.w3.org/1999/02/22-rdf-syntax-ns#",
            "rdfs": "http://www.w3.org/2000/01/rdf-schema#",
            "sec": "https://w3id.org/security#",
            "schema": "http://schema.org/",
            "xsd": "http://www.w3.org/2001/XMLSchema#",
            "Group": "https://www.w3.org/ns/activitystreams#Group",
            "claim": {"@id": "cred:claim", "@type": "@id"},
            "credential": {"@id": "cred:credential", "@type": "@id"},
            "issued": {"@id": "cred:issued", "@type": "xsd:dateTime"},
            "issuer": {"@id": "cred:issuer", "@type": "@id"},
            "recipient": {"@id": "cred:recipient", "@type": "@id"},
            "Credential": "cred:Credential",
            "CryptographicKeyCredential": "cred:CryptographicKeyCredential",
            "about": {"@id": "schema:about", "@type": "@id"},
            "address": {"@id": "schema:address", "@type": "@id"},
            "addressCountry": "schema:addressCountry",
            "addressLocality": "schema:addressLocality",
            "addressRegion": "schema:addressRegion",
            "comment": "rdfs:comment",
            "created": {"@id": "dc:created", "@type": "xsd:dateTime"},
            "creator": {"@id": "dc:creator", "@type": "@id"},
            "description": "schema:description",
            "email": "schema:email",
            "familyName": "schema:familyName",
            "givenName": "schema:givenName",
            "image": {"@id": "schema:image", "@type": "@id"},
            "label": "rdfs:label",
            "name": "schema:name",
            "postalCode": "schema:postalCode",
            "streetAddress": "schema:streetAddress",
            "title": "dc:title",
            "url": {"@id": "schema:url", "@type": "@id"},
            "Person": "schema:Person",
            "PostalAddress": "schema:PostalAddress",
            "Organization": "schema:Organization",
            "identityService": {
                "@id": "identity:identityService", "@type": "@id"
            },
            "idp": {"@id": "identity:idp", "@type": "@id"},
            "Identity": "identity:Identity",
            "paymentProcessor": "ps:processor",
            "preferences": {"@id": "ps:preferences", "@type": "@vocab"},
            "cipherAlgorithm": "sec:cipherAlgorithm",
            "cipherData": "sec:cipherData",
            "cipherKey": "sec:cipherKey",
            "digestAlgorithm": "sec:digestAlgorithm",
            "digestValue": "sec:digestValue",
            "domain": "sec:domain",
            "expires": {"@id": "sec:expiration", "@type": "xsd:dateTime"},
            "initializationVector": "sec:initializationVector",
            "member": {"@id": "schema:member", "@type": "@id"},
            "memberOf": {"@id": "schema:memberOf", "@type": "@id"},
            "nonce": "sec:nonce",
            "normalizationAlgorithm": "sec:normalizationAlgorithm",
            "owner": {"@id": "sec:owner", "@type": "@id"},
            "password": "sec:password",
            "privateKey": {"@id": "sec:privateKey", "@type": "@id"},
            "privateKeyPem": "sec:privateKeyPem",
            "publicKey": {"@id": "sec:publicKey", "@type": "@id"},
            "publicKeyPem": "sec:publicKeyPem",
            "publicKeyService": {
                "@id": "sec:publicKeyService", "@type": "@id"
            },
            "revoked": {"@id": "sec:revoked", "@type": "xsd:dateTime"},
            "signature": "sec:signature",
            "signatureAlgorithm": "sec:signatureAlgorithm",
            "signatureValue": "sec:signatureValue",
            "CryptographicKey": "sec:Key",
            "EncryptedMessage": "sec:EncryptedMessage",
            "GraphSignature2012": "sec:GraphSignature2012",
            "LinkedDataSignature2015": "sec:LinkedDataSignature2015",
            "accessControl": {"@id": "perm:accessControl", "@type": "@id"},
            "writePermission": {"@id": "perm:writePermission", "@type": "@id"}
        }
    }


def get_activitystreams_schema() -> {}:
    # https://www.w3.org/ns/activitystreams
    return {
        "@context": {
            "@vocab": "_:",
            "xsd": "http://www.w3.org/2001/XMLSchema#",
            "as": "https://www.w3.org/ns/activitystreams#",
            "ldp": "http://www.w3.org/ns/ldp#",
            "vcard": "http://www.w3.org/2006/vcard/ns#",
            "id": "@id",
            "type": "@type",
            "Accept": "as:Accept",
            "Activity": "as:Activity",
            "IntransitiveActivity": "as:IntransitiveActivity",
            "Add": "as:Add",
            "Announce": "as:Announce",
            "Application": "as:Application",
            "Arrive": "as:Arrive",
            "Article": "as:Article",
            "Audio": "as:Audio",
            "Block": "as:Block",
            "Collection": "as:Collection",
            "CollectionPage": "as:CollectionPage",
            "Relationship": "as:Relationship",
            "Create": "as:Create",
            "Delete": "as:Delete",
            "Dislike": "as:Dislike",
            "Document": "as:Document",
            "Event": "as:Event",
            "Follow": "as:Follow",
            "Flag": "as:Flag",
            "Group": "as:Group",
            "Ignore": "as:Ignore",
            "Image": "as:Image",
            "Invite": "as:Invite",
            "Join": "as:Join",
            "Leave": "as:Leave",
            "Like": "as:Like",
            "Link": "as:Link",
            "Mention": "as:Mention",
            "Note": "as:Note",
            "Object": "as:Object",
            "Offer": "as:Offer",
            "OrderedCollection": "as:OrderedCollection",
            "OrderedCollectionPage": "as:OrderedCollectionPage",
            "Organization": "as:Organization",
            "Page": "as:Page",
            "Person": "as:Person",
            "Place": "as:Place",
            "Profile": "as:Profile",
            "Question": "as:Question",
            "Reject": "as:Reject",
            "Remove": "as:Remove",
            "Service": "as:Service",
            "TentativeAccept": "as:TentativeAccept",
            "TentativeReject": "as:TentativeReject",
            "Tombstone": "as:Tombstone",
            "Undo": "as:Undo",
            "Update": "as:Update",
            "Video": "as:Video",
            "View": "as:View",
            "Listen": "as:Listen",
            "Read": "as:Read",
            "Move": "as:Move",
            "Travel": "as:Travel",
            "IsFollowing": "as:IsFollowing",
            "IsFollowedBy": "as:IsFollowedBy",
            "IsContact": "as:IsContact",
            "IsMember": "as:IsMember",
            "subject": {
                "@id": "as:subject",
                "@type": "@id"
            },
            "relationship": {
                "@id": "as:relationship",
                "@type": "@id"
            },
            "actor": {
                "@id": "as:actor",
                "@type": "@id"
            },
            "attributedTo": {
                "@id": "as:attributedTo",
                "@type": "@id"
            },
            "attachment": {
                "@id": "as:attachment",
                "@type": "@id"
            },
            "bcc": {
                "@id": "as:bcc",
                "@type": "@id"
            },
            "bto": {
                "@id": "as:bto",
                "@type": "@id"
            },
            "cc": {
                "@id": "as:cc",
                "@type": "@id"
            },
            "context": {
                "@id": "as:context",
                "@type": "@id"
            },
            "current": {
                "@id": "as:current",
                "@type": "@id"
            },
            "first": {
                "@id": "as:first",
                "@type": "@id"
            },
            "generator": {
                "@id": "as:generator",
                "@type": "@id"
            },
            "icon": {
                "@id": "as:icon",
                "@type": "@id"
            },
            "image": {
                "@id": "as:image",
                "@type": "@id"
            },
            "inReplyTo": {
                "@id": "as:inReplyTo",
                "@type": "@id"
            },
            "items": {
                "@id": "as:items",
                "@type": "@id"
            },
            "instrument": {
                "@id": "as:instrument",
                "@type": "@id"
            },
            "orderedItems": {
                "@id": "as:items",
                "@type": "@id",
                "@container": "@list"
            },
            "last": {
                "@id": "as:last",
                "@type": "@id"
            },
            "location": {
                "@id": "as:location",
                "@type": "@id"
            },
            "next": {
                "@id": "as:next",
                "@type": "@id"
            },
            "object": {
                "@id": "as:object",
                "@type": "@id"
            },
            "oneOf": {
                "@id": "as:oneOf",
                "@type": "@id"
            },
            "anyOf": {
                "@id": "as:anyOf",
                "@type": "@id"
            },
            "closed": {
                "@id": "as:closed",
                "@type": "xsd:dateTime"
            },
            "origin": {
                "@id": "as:origin",
                "@type": "@id"
            },
            "accuracy": {
                "@id": "as:accuracy",
                "@type": "xsd:float"
            },
            "prev": {
                "@id": "as:prev",
                "@type": "@id"
            },
            "preview": {
                "@id": "as:preview",
                "@type": "@id"
            },
            "replies": {
                "@id": "as:replies",
                "@type": "@id"
            },
            "result": {
                "@id": "as:result",
                "@type": "@id"
            },
            "audience": {
                "@id": "as:audience",
                "@type": "@id"
            },
            "partOf": {
                "@id": "as:partOf",
                "@type": "@id"
            },
            "tag": {
                "@id": "as:tag",
                "@type": "@id"
            },
            "target": {
                "@id": "as:target",
                "@type": "@id"
            },
            "to": {
                "@id": "as:to",
                "@type": "@id"
            },
            "url": {
                "@id": "as:url",
                "@type": "@id"
            },
            "altitude": {
                "@id": "as:altitude",
                "@type": "xsd:float"
            },
            "content": "as:content",
            "contentMap": {
                "@id": "as:content",
                "@container": "@language"
            },
            "name": "as:name",
            "nameMap": {
                "@id": "as:name",
                "@container": "@language"
            },
            "duration": {
                "@id": "as:duration",
                "@type": "xsd:duration"
            },
            "endTime": {
                "@id": "as:endTime",
                "@type": "xsd:dateTime"
            },
            "height": {
                "@id": "as:height",
                "@type": "xsd:nonNegativeInteger"
            },
            "href": {
                "@id": "as:href",
                "@type": "@id"
            },
            "hreflang": "as:hreflang",
            "latitude": {
                "@id": "as:latitude",
                "@type": "xsd:float"
            },
            "longitude": {
                "@id": "as:longitude",
                "@type": "xsd:float"
            },
            "mediaType": "as:mediaType",
            "published": {
                "@id": "as:published",
                "@type": "xsd:dateTime"
            },
            "radius": {
                "@id": "as:radius",
                "@type": "xsd:float"
            },
            "rel": "as:rel",
            "startIndex": {
                "@id": "as:startIndex",
                "@type": "xsd:nonNegativeInteger"
            },
            "startTime": {
                "@id": "as:startTime",
                "@type": "xsd:dateTime"
            },
            "summary": "as:summary",
            "summaryMap": {
                "@id": "as:summary",
                "@container": "@language"
            },
            "totalItems": {
                "@id": "as:totalItems",
                "@type": "xsd:nonNegativeInteger"
            },
            "units": "as:units",
            "updated": {
                "@id": "as:updated",
                "@type": "xsd:dateTime"
            },
            "width": {
                "@id": "as:width",
                "@type": "xsd:nonNegativeInteger"
            },
            "describes": {
                "@id": "as:describes",
                "@type": "@id"
            },
            "formerType": {
                "@id": "as:formerType",
                "@type": "@id"
            },
            "deleted": {
                "@id": "as:deleted",
                "@type": "xsd:dateTime"
            },
            "inbox": {
                "@id": "ldp:inbox",
                "@type": "@id"
            },
            "outbox": {
                "@id": "as:outbox",
                "@type": "@id"
            },
            "following": {
                "@id": "as:following",
                "@type": "@id"
            },
            "followers": {
                "@id": "as:followers",
                "@type": "@id"
            },
            "streams": {
                "@id": "as:streams",
                "@type": "@id"
            },
            "preferredUsername": "as:preferredUsername",
            "endpoints": {
                "@id": "as:endpoints",
                "@type": "@id"
            },
            "uploadMedia": {
                "@id": "as:uploadMedia",
                "@type": "@id"
            },
            "proxyUrl": {
                "@id": "as:proxyUrl",
                "@type": "@id"
            },
            "liked": {
                "@id": "as:liked",
                "@type": "@id"
            },
            "oauthAuthorizationEndpoint": {
                "@id": "as:oauthAuthorizationEndpoint",
                "@type": "@id"
            },
            "oauthTokenEndpoint": {
                "@id": "as:oauthTokenEndpoint",
                "@type": "@id"
            },
            "provideClientKey": {
                "@id": "as:provideClientKey",
                "@type": "@id"
            },
            "signClientKey": {
                "@id": "as:signClientKey",
                "@type": "@id"
            },
            "sharedInbox": {
                "@id": "as:sharedInbox",
                "@type": "@id"
            },
            "Public": {
                "@id": "as:Public",
                "@type": "@id"
            },
            "source": "as:source",
            "likes": {
                "@id": "as:likes",
                "@type": "@id"
            },
            "shares": {
                "@id": "as:shares",
                "@type": "@id"
            },
            "alsoKnownAs": {
                "@id": "as:alsoKnownAs",
                "@type": "@id"
            }
        }
    }
