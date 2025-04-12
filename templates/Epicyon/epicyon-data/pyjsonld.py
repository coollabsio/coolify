"""
Python implementation of JSON-LD processor

This implementation is ported from the JavaScript implementation of
JSON-LD.

.. module:: jsonld
  :synopsis: Python implementation of JSON-LD

.. moduleauthor:: Dave Longley
.. moduleauthor:: Mike Johnson
.. moduleauthor:: Tim McNamara <tim.mcnamara@okfn.org>
"""

__copyright__ = 'Copyright (c) 2011-2014 Digital Bazaar, Inc.'
__license__ = 'New BSD license'
__version__ = '0.6.8'
__module_group__ = "Security"

__all__ = [
    'compact', 'expand', 'flatten', 'frame', 'link', 'from_rdf', 'to_rdf',
    'normalize', 'set_document_loader', 'get_document_loader',
    'parse_link_header', 'load_document',
    'register_rdf_parser', 'unregister_rdf_parser',
    'JsonLdProcessor', 'JsonLdError', 'ActiveContextCache']

import copy
import hashlib
import json
import os
import posixpath
import re
import socket
import ssl
import string
import sys
import traceback
from collections import deque, namedtuple
from numbers import Integral, Real

from context import getApschemaV1_9
from context import getApschemaV1_10
from context import getApschemaV1_20
from context import getApschemaV1_21
from context import getLitepubV0_1
from context import get_litepub_social
from context import get_v1schema
from context import get_v1security_schema
from context import get_activitystreams_schema
from context import get_webfinger_schema
from context import get_did_v1_schema
from context import get_multikey_v1_schema
from context import get_data_integrity_v1_schema

try:
    from functools import cmp_to_key
except ImportError:
    def cmp_to_key(mycmp):
        """
        Convert a cmp= function into a key= function

        Source: http://hg.python.org/cpython/file/default/Lib/functools.py
        """
        class K(object):
            __slots__ = ['obj']

            def __init__(self, obj):
                self.obj = obj

            def __lt__(self, other):
                return mycmp(self.obj, other.obj) < 0

            def __gt__(self, other):
                return mycmp(self.obj, other.obj) > 0

            def __eq__(self, other):
                return mycmp(self.obj, other.obj) == 0

            def __le__(self, other):
                return mycmp(self.obj, other.obj) <= 0

            def __ge__(self, other):
                return mycmp(self.obj, other.obj) >= 0

            def __ne__(self, other):
                return mycmp(self.obj, other.obj) != 0
            __hash__ = None
        return K

# support python 2
if sys.version_info[0] >= 3:
    from urllib.request import HTTPSHandler
    import urllib.parse as urllib_parse
    from http.client import HTTPSConnection
    basestring = str

    def cmp(a, b):
        return (a > b) - (a < b)
else:
    from urllib2 import HTTPSHandler
    import urlparse as urllib_parse
    from httplib import HTTPSConnection

# XSD constants
XSD_BOOLEAN = 'http://www.w3.org/2001/XMLSchema#boolean'
XSD_DOUBLE = 'http://www.w3.org/2001/XMLSchema#double'
XSD_INTEGER = 'http://www.w3.org/2001/XMLSchema#integer'
XSD_STRING = 'http://www.w3.org/2001/XMLSchema#string'

# RDF constants
RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#'
RDF_LIST = RDF + 'List'
RDF_FIRST = RDF + 'first'
RDF_REST = RDF + 'rest'
RDF_NIL = RDF + 'nil'
RDF_TYPE = RDF + 'type'
RDF_LANGSTRING = RDF + 'lang_string'

# JSON-LD keywords
KEYWORDS = [
    '@base',
    '@context',
    '@container',
    '@default',
    '@embed',
    '@explicit',
    '@graph',
    '@id',
    '@index',
    '@language',
    '@list',
    '@omitDefault',
    '@preserve',
    '@requireAll',
    '@reverse',
    '@set',
    '@type',
    '@value',
    '@vocab']

# JSON-LD link header rel
LINK_HEADER_REL = 'http://www.w3.org/ns/json-ld#context'

# Restraints
MAX_CONTEXT_URLS = 10


def compact(input_, ctx, options=None):
    """
    Performs JSON-LD compaction.

    :param input_: the JSON-LD input to compact.
    :param ctx: the JSON-LD context to compact with.
    :param [options]: the options to use.
      [base] the base IRI to use.
      [compactArrays] True to compact arrays to single values when
        appropriate, False not to (default: True).
      [graph] True to always output a top-level graph (default: False).
      [expandContext] a context to expand with.
      [documentLoader(url)] the document loader
        (default: _default_document_loader).

    :return: the compacted JSON-LD output.
    """
    return JsonLdProcessor().compact(input_, ctx, options)


def expand(input_, options=None):
    """
    Performs JSON-LD expansion.

    :param input_: the JSON-LD input to expand.
    :param [options]: the options to use.
      [base] the base IRI to use.
      [expandContext] a context to expand with.
      [documentLoader(url)] the document loader
        (default: _default_document_loader).

    :return: the expanded JSON-LD output.
    """
    return JsonLdProcessor().expand(input_, options)


def flatten(input_, ctx=None, options=None):
    """
    Performs JSON-LD flattening.

    :param input_: the JSON-LD input to flatten.
    :param ctx: the JSON-LD context to compact with (default: None).
    :param [options]: the options to use.
      [base] the base IRI to use.
      [expandContext] a context to expand with.
      [documentLoader(url)] the document loader
        (default: _default_document_loader).

    :return: the flattened JSON-LD output.
    """
    return JsonLdProcessor().flatten(input_, ctx, options)


def frame(input_, frame, options=None):
    """
    Performs JSON-LD framing.

    :param input_: the JSON-LD input to frame.
    :param frame: the JSON-LD frame to use.
    :param [options]: the options to use.
      [base] the base IRI to use.
      [expandContext] a context to expand with.
      [embed] default @embed flag (default: True).
      [explicit] default @explicit flag (default: False).
      [requireAll] default @requireAll flag (default: True).
      [omitDefault] default @omitDefault flag (default: False).
      [documentLoader(url)] the document loader
        (default: _default_document_loader).

    :return: the framed JSON-LD output.
    """
    return JsonLdProcessor().frame(input_, frame, options)


def link(input_, ctx, options=None):
    """
    **Experimental**

    Links a JSON-LD document's nodes in memory.

    :param input_: the JSON-LD document to link.
    :param ctx: the JSON-LD context to apply or None.
    :param [options]: the options to use.
      [base] the base IRI to use.
      [expandContext] a context to expand with.
      [documentLoader(url)] the document loader
        (default: _default_document_loader).

    :return: the linked JSON-LD output.
    """
    # API matches running frame with a wildcard frame and embed: '@link'
    # get arguments
    frame = {'@embed': '@link'}
    if ctx:
        frame['@context'] = ctx
    frame['@embed'] = '@link'
    return frame(input, frame, options)


def normalize(input_: {}, options=None):
    """
    Performs JSON-LD normalization.

    :param input_: the JSON-LD input to normalize.
    :param [options]: the options to use.
      [base] the base IRI to use.
      [format] the format if output is a string:
        'application/nquads' for N-Quads.
      [documentLoader(url)] the document loader
        (default: _default_document_loader).

    :return: the normalized JSON-LD output.
    """
    return JsonLdProcessor().normalize(input_, options)


def from_rdf(input_, options=None):
    """
    Converts an RDF dataset to JSON-LD.

    :param input_: a serialized string of RDF in a format specified
      by the format option or an RDF dataset to convert.
    :param [options]: the options to use:
      [format] the format if input is a string:
        'application/nquads' for N-Quads (default: 'application/nquads').
      [useRdfType] True to use rdf:type, False to use @type (default: False).
      [useNativeTypes] True to convert XSD types into native types
        (boolean, integer, double), False not to (default: True).

    :return: the JSON-LD output.
    """
    return JsonLdProcessor().from_rdf(input_, options)


def to_rdf(input_, options=None):
    """
    Outputs the RDF dataset found in the given JSON-LD object.

    :param input_: the JSON-LD input.
    :param [options]: the options to use.
      [base] the base IRI to use.
      [format] the format to use to output a string:
        'application/nquads' for N-Quads.
      [produceGeneralizedRdf] true to output generalized RDF, false
        to produce only standard RDF (default: false).
      [documentLoader(url)] the document loader
        (default: _default_document_loader).

    :return: the resulting RDF dataset (or a serialization of it).
    """
    return JsonLdProcessor().to_rdf(input_, options)


def set_document_loader(load_document):
    """
    Sets the default JSON-LD document loader.

    :param load_document(url): the document loader to use.
    """
    global _default_document_loader
    _default_document_loader = load_document


def get_document_loader():
    """
    Gets the default JSON-LD document loader.

    :return: the default document loader.
    """
    return _default_document_loader


def parse_link_header(header):
    """
    Parses a link header. The results will be key'd by the value of "rel".

    Link: <http://json-ld.org/contexts/person.jsonld>; \
      rel="http://www.w3.org/ns/json-ld#context"; type="application/ld+json"

    Parses as: {
      'http://www.w3.org/ns/json-ld#context': {
        target: http://json-ld.org/contexts/person.jsonld,
        type: 'application/ld+json'
      }
    }

    If there is more than one "rel" with the same IRI, then entries in the
    resulting map for that "rel" will be lists.

    :param header: the link header to parse.

    :return: the parsed result.
    """
    rval = {}
    # split on unbracketed/unquoted commas
    entries = re.findall(r'(?:<[^>]*?>|"[^"]*?"|[^,])+', header)
    if not entries:
        return rval
    r_link_header = r'\s*<([^>]*?)>\s*(?:;\s*(.*))?'
    for entry in entries:
        ldmatch = re.search(r_link_header, entry)
        if not ldmatch:
            continue
        ldmatch = ldmatch.groups()
        result = {'target': ldmatch[0]}
        params = ldmatch[1]
        r_params = r'(.*?)=(?:(?:"([^"]*?)")|([^"]*?))\s*(?:(?:;\s*)|$)'
        matches = re.findall(r_params, params)
        for ldmatch in matches:
            result[ldmatch[0]] = \
                ldmatch[2] if ldmatch[1] is None else ldmatch[1]
        rel = result.get('rel', '')
        if isinstance(rval.get(rel), list):
            rval[rel].append(result)
        elif rel in rval:
            rval[rel] = [rval[rel], result]
        else:
            rval[rel] = result
    return rval


def load_document(url):
    """
    Retrieves JSON-LD at the given URL.

    :param url: the URL to retrieve.

    :return: the RemoteDocument.
    """
    try:
        # validate URL
        pieces = urllib_parse.urlparse(url)
        if (not all([pieces.scheme, pieces.netloc]) or
            pieces.scheme not in ['http', 'https', 'hyper', 'ipfs', 'ipns'] or
            set(pieces.netloc) > set(
                string.ascii_letters + string.digits + '-.:')):
            raise JsonLdError(
                'URL could not be dereferenced; only http/https/dat '
                'URLs are supported.',
                'jsonld.InvalidUrl', {'url': url},
                code='loading document failed')

        if url == 'https://w3id.org/identity/v1':
            doc = {
                'contextUrl': None,
                'documentUrl': url,
                'document': get_v1schema()
            }
            return doc
        if url == 'https://w3id.org/security/v1':
            doc = {
                'contextUrl': None,
                'documentUrl': url,
                'document': get_v1security_schema()
            }
            return doc
        elif url == 'https://www.w3.org/ns/activitystreams':
            doc = {
                'contextUrl': None,
                'documentUrl': url,
                'document': get_activitystreams_schema()
            }
            return doc
        elif url.endswith('/apschema/v1.9'):
            doc = {
                'contextUrl': None,
                'documentUrl': url,
                'document': getApschemaV1_9()
            }
            return doc
        elif url.endswith('/apschema/v1.10'):
            doc = {
                'contextUrl': None,
                'documentUrl': url,
                'document': getApschemaV1_10()
            }
            return doc
        elif url.endswith('/apschema/v1.20'):
            doc = {
                'contextUrl': None,
                'documentUrl': url,
                'document': getApschemaV1_20()
            }
            return doc
        elif url.endswith('/apschema/v1.21'):
            doc = {
                'contextUrl': None,
                'documentUrl': url,
                'document': getApschemaV1_21()
            }
            return doc
        elif url.endswith('/litepub-0.1.jsonld'):
            doc = {
                'contextUrl': None,
                'documentUrl': url,
                'document': getLitepubV0_1()
            }
            return doc
        elif (url.endswith('/socialweb/webfinger') or
              url.endswith('/socialweb/webfinger.jsonld')):
            doc = {
                'contextUrl': None,
                'documentUrl': url,
                'document': get_webfinger_schema()
            }
            return doc
        elif (url == "https://w3id.org/security/multikey/v1" or
              url.endswith("/vc-data-integrity/contexts/multikey/v1.jsonld")):
            doc = {
                'contextUrl': None,
                'documentUrl': url,
                'document': get_multikey_v1_schema()
            }
            return doc
        elif (url == "https://w3id.org/security/data-integrity/v1" or
              url.endswith("/contexts/data-integrity/v1.jsonld")):
            doc = {
                'contextUrl': None,
                'documentUrl': url,
                'document': get_data_integrity_v1_schema()
            }
            return doc
        elif url == 'https://litepub.social/litepub/context.jsonld':
            doc = {
                'contextUrl': None,
                'documentUrl': url,
                'document': get_litepub_social()
            }
            return doc
        elif url == 'https://www.w3.org/ns/did/v1':
            doc = {
                'contextUrl': None,
                'documentUrl': url,
                'document': get_did_v1_schema()
            }
            return doc
        return None
    except JsonLdError as ex:
        raise ex
    except BaseException as cause:
        raise JsonLdError(
            'Could not retrieve a JSON-LD document from the URL.',
            'jsonld.LoadDocumentError', code='loading document failed',
            cause=cause)


def register_rdf_parser(content_type, parser):
    """
    Registers a global RDF parser by content-type, for use with
    from_rdf. Global parsers will be used by JsonLdProcessors that
    do not register their own parsers.

    :param content_type: the content-type for the parser.
    :param parser(input): the parser function (takes a string as
             a parameter and returns an RDF dataset).
    """
    global _rdf_parsers
    _rdf_parsers[content_type] = parser


def unregister_rdf_parser(content_type):
    """
    Unregisters a global RDF parser by content-type.

    :param content_type: the content-type for the parser.
    """
    global _rdf_parsers
    if content_type in _rdf_parsers:
        del _rdf_parsers[content_type]


def prepend_base(base, iri):
    """
    Prepends a base IRI to the given relative IRI.

    :param base: the base IRI.
    :param iri: the relative IRI.

    :return: the absolute IRI.
    """
    # skip IRI processing
    if base is None:
        return iri

    # already an absolute iri
    if _is_absolute_iri(iri):
        return iri

    # parse IRIs
    base = parse_url(base)
    rel = parse_url(iri)

    # per RFC3986 5.2.2
    transform = {
        'scheme': base.scheme
    }

    if rel.authority is not None:
        transform['authority'] = rel.authority
        transform['path'] = rel.path
        transform['query'] = rel.query
    else:
        transform['authority'] = base.authority

        if rel.path == '':
            transform['path'] = base.path
            if rel.query is not None:
                transform['query'] = rel.query
            else:
                transform['query'] = base.query
        else:
            if rel.path.startswith('/'):
                # IRI represents an absolute path
                transform['path'] = rel.path
            else:
                # merge paths
                path = base.path

                # append relative path to the end of the last
                # directory from base
                if rel.path != '':
                    path = path[0:path.rfind('/') + 1]
                    if len(path) > 0 and not path.endswith('/'):
                        path += '/'
                    path += rel.path

                transform['path'] = path

            transform['query'] = rel.query

    # normalize path
    path = transform['path']
    add_slash = path.endswith('/')
    path = posixpath.normpath(path)
    if not path.endswith('/') and add_slash:
        path += '/'
    # do not include '.' path
    if path == '.':
        path = ''
    transform['path'] = path

    transform['fragment'] = rel.fragment

    # construct URL
    rval = unparse_url(transform)

    # handle empty base case
    if rval == '':
        rval = './'

    return rval


def remove_base(base, iri):
    """
    Removes a base IRI from the given absolute IRI.

    :param base: the base IRI.
    :param iri: the absolute IRI.

    :return: the relative IRI if relative to base, otherwise the absolute IRI.
    """
    # skip IRI processing
    if base is None:
        return iri

    base = parse_url(base)
    rel = parse_url(iri)

    # schemes and network locations (authorities) don't match, don't alter IRI
    if not (base.scheme == rel.scheme and base.authority == rel.authority):
        return iri

    path = posixpath.relpath(rel.path, base.path) if rel.path else ''
    path = posixpath.normpath(path)
    # workaround a relpath bug in Python 2.6 (http://bugs.python.org/issue5117)
    if base.path == '/' and path.startswith('../'):
        path = path[3:]
    if path == '.' and not rel.path.endswith('/') and not (
            rel.query or rel.fragment):
        path = posixpath.basename(rel.path)
    if rel.path.endswith('/') and not path.endswith('/'):
        path += '/'

    # adjustments for base that is not a directory
    if not base.path.endswith('/'):
        if path.startswith('../'):
            path = path[3:]
        elif path.startswith('./'):
            path = path[2:]
        elif path.startswith('.'):
            path = path[1:]

    return unparse_url((None, None, path, rel.query, rel.fragment)) or './'


ParsedUrl = namedtuple(
    'ParsedUrl', ['scheme', 'authority', 'path', 'query', 'fragment'])


def parse_url(url):
    # regex from RFC 3986
    p = r'^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?'
    m = re.match(p, url)
    return ParsedUrl(*m.groups())


def unparse_url(parsed):
    if isinstance(parsed, dict):
        parsed = ParsedUrl(**parsed)
    elif isinstance(parsed, list) or isinstance(parsed, tuple):
        parsed = ParsedUrl(*parsed)
    rval = ''
    if parsed.scheme:
        rval += parsed.scheme + ':'
    if parsed.authority is not None:
        rval += '//' + parsed.authority
    rval += parsed.path
    if parsed.query is not None:
        rval += '?' + parsed.query
    if parsed.fragment is not None:
        rval += '#' + parsed.fragment
    return rval


# The default JSON-LD document loader.
_default_document_loader = load_document

# Registered global RDF parsers hashed by content-type.
_rdf_parsers = {}


class JsonLdProcessor(object):
    """
    A JSON-LD processor.
    """

    def __init__(self):
        """
        Initialize the JSON-LD processor.
        """
        # processor-specific RDF parsers
        self.rdf_parsers = None

    def compact(self, input_, ctx, options):
        """
        Performs JSON-LD compaction.

        :param input_: the JSON-LD input to compact.
        :param ctx: the context to compact with.
        :param options: the options to use.
          [base] the base IRI to use.
          [compactArrays] True to compact arrays to single values when
            appropriate, False not to (default: True).
          [graph] True to always output a top-level graph (default: False).
          [expandContext] a context to expand with.
          [skipExpansion] True to assume the input is expanded and skip
            expansion, False not to, (default: False).
          [activeCtx] True to also return the active context used.
          [documentLoader(url)] the document loader
            (default: _default_document_loader).

        :return: the compacted JSON-LD output.
        """
        if ctx is None:
            raise JsonLdError(
                'The compaction context must not be null.',
                'jsonld.CompactError', code='invalid local context')

        # nothing to compact
        if input_ is None:
            return None

        # set default options
        options = options or {}
        options.setdefault('base', input_ if _is_string(input_) else '')
        options.setdefault('compactArrays', True)
        options.setdefault('graph', False)
        options.setdefault('skipExpansion', False)
        options.setdefault('activeCtx', False)
        options.setdefault('documentLoader', _default_document_loader)
        options.setdefault('link', False)
        if options['link']:
            # force skip expansion when linking, "link" is not part of the
            # public API, it should only be called from framing
            options['skipExpansion'] = True

        if options['skipExpansion']:
            expanded = input_
        else:
            # expand input
            try:
                expanded = self.expand(input_, options)
            except JsonLdError as cause:
                raise JsonLdError(
                    'Could not expand input before compaction.',
                    'jsonld.CompactError', cause=cause)

        # process context
        active_ctx = self._get_initial_context(options)
        try:
            active_ctx = self.process_context(active_ctx, ctx, options)
        except JsonLdError as cause:
            raise JsonLdError(
                'Could not process context before compaction.',
                'jsonld.CompactError', cause=cause)

        # do compaction
        compacted = self._compact(active_ctx, None, expanded, options)

        if (options['compactArrays'] and not options['graph'] and
                _is_array(compacted)):
            # simplify to a single item
            if len(compacted) == 1:
                compacted = compacted[0]
            # simplify to an empty object
            elif len(compacted) == 0:
                compacted = {}
        # always use an array if graph options is on
        elif options['graph']:
            compacted = JsonLdProcessor.arrayify(compacted)

        # follow @context key
        if _is_object(ctx) and '@context' in ctx:
            ctx = ctx['@context']

        # build output context
        ctx = copy.deepcopy(ctx)
        ctx = JsonLdProcessor.arrayify(ctx)

        # remove empty contexts
        tmp = ctx
        ctx = []
        for v in tmp:
            if not _is_object(v) or len(v) > 0:
                ctx.append(v)

        # remove array if only one context
        ctx_length = len(ctx)
        has_context = (ctx_length > 0)
        if ctx_length == 1:
            ctx = ctx[0]

        # add context and/or @graph
        if _is_array(compacted):
            # use '@graph' keyword
            kwgraph = self._compact_iri(active_ctx, '@graph')
            graph = compacted
            compacted = {}
            if has_context:
                compacted['@context'] = ctx
            compacted[kwgraph] = graph
        elif _is_object(compacted) and has_context:
            # reorder keys so @context is first
            graph = compacted
            compacted = {}
            compacted['@context'] = ctx
            for k, v in graph.items():
                compacted[k] = v

        if options['activeCtx']:
            return {'compacted': compacted, 'activeCtx': active_ctx}
        else:
            return compacted

    def expand(self, input_, options):
        """
        Performs JSON-LD expansion.

        :param input_: the JSON-LD input to expand.
        :param options: the options to use.
          [base] the base IRI to use.
          [expandContext] a context to expand with.
          [keepFreeFloatingNodes] True to keep free-floating nodes,
            False not to (default: False).
          [documentLoader(url)] the document loader
            (default: _default_document_loader).

        :return: the expanded JSON-LD output.
        """
        # set default options
        options = options or {}
        options.setdefault('keepFreeFloatingNodes', False)
        options.setdefault('documentLoader', _default_document_loader)

        # if input is a string, attempt to dereference remote document
        if _is_string(input_):
            remote_doc = options['documentLoader'](input_)
        else:
            remote_doc = {
                'contextUrl': None,
                'documentUrl': None,
                'document': input_
            }

        try:
            if remote_doc['document'] is None:
                raise JsonLdError(
                    'No remote document found at the given URL.',
                    'jsonld.NullRemoteDocument')
            if _is_string(remote_doc['document']):
                remote_doc['document'] = json.loads(remote_doc['document'])
        except BaseException as cause:
            raise JsonLdError(
                'Could not retrieve a JSON-LD document from the URL.',
                'jsonld.LoadDocumentError',
                {'remoteDoc': remote_doc}, code='loading document failed',
                cause=cause)

        # set default base
        options.setdefault('base', remote_doc['documentUrl'] or '')

        # build meta-object and retrieve all @context urls
        input_ = {
            'document': copy.deepcopy(remote_doc['document']),
            'remoteContext': {'@context': remote_doc['contextUrl']}
        }
        if 'expandContext' in options:
            expand_context = copy.deepcopy(options['expandContext'])
            if _is_object(expand_context) and '@context' in expand_context:
                input_['expandContext'] = expand_context
            else:
                input_['expandContext'] = {'@context': expand_context}

        try:
            self._retrieve_context_urls(
                input_, {}, options['documentLoader'], options['base'])
        except BaseException as cause:
            raise JsonLdError(
                'Could not perform JSON-LD expansion.',
                'jsonld.ExpandError', cause=cause)

        active_ctx = self._get_initial_context(options)
        document = input_['document']
        remote_context = input_['remoteContext']['@context']

        # process optional expandContext
        if 'expandContext' in input_:
            active_ctx = self.process_context(
                active_ctx, input_['expandContext']['@context'], options)

        # process remote context from HTTP Link Header
        if remote_context is not None:
            active_ctx = self.process_context(
                active_ctx, remote_context, options)

        # do expansion
        expanded = self._expand(active_ctx, None, document, options, False)

        # optimize away @graph with no other properties
        if (_is_object(expanded) and '@graph' in expanded and
                len(expanded) == 1):
            expanded = expanded['@graph']
        elif expanded is None:
            expanded = []

        # normalize to an array
        return JsonLdProcessor.arrayify(expanded)

    def flatten(self, input_, ctx, options):
        """
        Performs JSON-LD flattening.

        :param input_: the JSON-LD input to flatten.
        :param ctx: the JSON-LD context to compact with (default: None).
        :param options: the options to use.
          [base] the base IRI to use.
          [expandContext] a context to expand with.
          [documentLoader(url)] the document loader
            (default: _default_document_loader).

        :return: the flattened JSON-LD output.
        """
        options = options or {}
        options.setdefault('base', input_ if _is_string(input_) else '')
        options.setdefault('documentLoader', _default_document_loader)

        try:
            # expand input
            expanded = self.expand(input_, options)
        except BaseException as cause:
            raise JsonLdError(
                'Could not expand input before flattening.',
                'jsonld.FlattenError', cause=cause)

        # do flattening
        flattened = self._flatten(expanded)

        if ctx is None:
            return flattened

        # compact result (force @graph option to true, skip expansion)
        options['graph'] = True
        options['skipExpansion'] = True
        try:
            compacted = self.compact(flattened, ctx, options)
        except BaseException as cause:
            raise JsonLdError(
                'Could not compact flattened output.',
                'jsonld.FlattenError', cause=cause)

        return compacted

    def frame(self, input_, frame, options):
        """
        Performs JSON-LD framing.

        :param input_: the JSON-LD object to frame.
        :param frame: the JSON-LD frame to use.
        :param options: the options to use.
          [base] the base IRI to use.
          [expandContext] a context to expand with.
          [embed] default @embed flag: '@last', '@always', '@never', '@link'
            (default: '@last').
          [explicit] default @explicit flag (default: False).
          [requireAll] default @requireAll flag (default: True).
          [omitDefault] default @omitDefault flag (default: False).
          [documentLoader(url)] the document loader
            (default: _default_document_loader).

        :return: the framed JSON-LD output.
        """
        # set default options
        options = options or {}
        options.setdefault('base', input_ if _is_string(input_) else '')
        options.setdefault('compactArrays', True)
        options.setdefault('embed', '@last')
        options.setdefault('explicit', False)
        options.setdefault('requireAll', True)
        options.setdefault('omitDefault', False)
        options.setdefault('documentLoader', _default_document_loader)

        # if frame is a string, attempt to dereference remote document
        if _is_string(frame):
            remote_frame = options['documentLoader'](frame)
        else:
            remote_frame = {
                'contextUrl': None,
                'documentUrl': None,
                'document': frame
            }

        try:
            if remote_frame['document'] is None:
                raise JsonLdError(
                    'No remote document found at the given URL.',
                    'jsonld.NullRemoteDocument')
            if _is_string(remote_frame['document']):
                remote_frame['document'] = json.loads(remote_frame['document'])
        except BaseException as cause:
            raise JsonLdError(
                'Could not retrieve a JSON-LD document from the URL.',
                'jsonld.LoadDocumentError',
                {'remoteDoc': remote_frame}, code='loading document failed',
                cause=cause)

        # preserve frame context
        frame = remote_frame['document']
        if frame is not None:
            ctx = frame.get('@context', {})
            if remote_frame['contextUrl'] is not None:
                if ctx is not None:
                    ctx = remote_frame['contextUrl']
                else:
                    ctx = JsonLdProcessor.arrayify(ctx)
                    ctx.append(remote_frame['contextUrl'])
                frame['@context'] = ctx

        try:
            # expand input
            expanded = self.expand(input_, options)
        except JsonLdError as cause:
            raise JsonLdError(
                'Could not expand input before framing.',
                'jsonld.FrameError', cause=cause)

        try:
            # expand frame
            opts = copy.deepcopy(options)
            opts['keepFreeFloatingNodes'] = True
            expanded_frame = self.expand(frame, opts)
        except JsonLdError as cause:
            raise JsonLdError(
                'Could not expand frame before framing.',
                'jsonld.FrameError', cause=cause)

        # do framing
        framed = self._frame(expanded, expanded_frame, options)

        try:
            # compact result (force @graph option to True, skip expansion,
            # check for linked embeds)
            options['graph'] = True
            options['skipExpansion'] = True
            options['link'] = {}
            options['activeCtx'] = True
            result = self.compact(framed, ctx, options)
        except JsonLdError as cause:
            raise JsonLdError(
                'Could not compact framed output.',
                'jsonld.FrameError', cause=cause)

        compacted = result['compacted']
        active_ctx = result['activeCtx']

        # get graph alias
        graph = self._compact_iri(active_ctx, '@graph')
        # remove @preserve from results
        compacted[graph] = self._remove_preserve(
            active_ctx, compacted[graph], options)
        return compacted

    def normalize(self, input_, options):
        """
        Performs RDF normalization on the given JSON-LD input.

        :param input_: the JSON-LD input to normalize.
        :param options: the options to use.
          [base] the base IRI to use.
          [format] the format if output is a string:
            'application/nquads' for N-Quads.
          [documentLoader(url)] the document loader
            (default: _default_document_loader).

        :return: the normalized output.
        """
        # set default options
        options = options or {}
        options.setdefault('base', input_ if _is_string(input_) else '')
        options.setdefault('documentLoader', _default_document_loader)

        try:
            # convert to RDF dataset then do normalization
            opts = copy.deepcopy(options)
            if 'format' in opts:
                del opts['format']
            opts['produceGeneralizedRdf'] = False
            dataset = self.to_rdf(input_, opts)
        except JsonLdError as cause:
            raise JsonLdError(
                'Could not convert input to RDF dataset before normalization.',
                'jsonld.NormalizeError', cause=cause)

        # do normalization
        return self._normalize(dataset, options)

    def from_rdf(self, dataset, options):
        """
        Converts an RDF dataset to JSON-LD.

        :param dataset: a serialized string of RDF in a format specified by
          the format option or an RDF dataset to convert.
        :param options: the options to use.
          [format] the format if input is a string:
            'application/nquads' for N-Quads (default: 'application/nquads').
          [useRdfType] True to use rdf:type, False to use @type
            (default: False).
          [useNativeTypes] True to convert XSD types into native types
            (boolean, integer, double), False not to (default: False).

        :return: the JSON-LD output.
        """
        global _rdf_parsers

        # set default options
        options = options or {}
        options.setdefault('useRdfType', False)
        options.setdefault('useNativeTypes', False)

        if ('format' not in options) and _is_string(dataset):
            options['format'] = 'application/nquads'

        # handle special format
        if 'format' in options:
            # supported formats (processor-specific and global)
            if ((self.rdf_parsers is not None and
                 not options['format'] in self.rdf_parsers) or
                (self.rdf_parsers is None and
                 not options['format'] in _rdf_parsers)):
                raise JsonLdError(
                    'Unknown input format.',
                    'jsonld.UnknownFormat', {'format': options['format']})

            if self.rdf_parsers is not None:
                parser = self.rdf_parsers[options['format']]
            else:
                parser = _rdf_parsers[options['format']]
            dataset = parser(dataset)

        # convert from RDF
        return self._from_rdf(dataset, options)

    def to_rdf(self, input_, options):
        """
        Outputs the RDF dataset found in the given JSON-LD object.

        :param input_: the JSON-LD input.
        :param options: the options to use.
          [base] the base IRI to use.
          [format] the format if input is a string:
            'application/nquads' for N-Quads.
          [produceGeneralizedRdf] true to output generalized RDF, false
            to produce only standard RDF (default: false).
          [documentLoader(url)] the document loader
            (default: _default_document_loader).

        :return: the resulting RDF dataset (or a serialization of it).
        """
        # set default options
        options = options or {}
        options.setdefault('base', input_ if _is_string(input_) else '')
        options.setdefault('produceGeneralizedRdf', False)
        options.setdefault('documentLoader', _default_document_loader)

        try:
            # expand input
            expanded = self.expand(input_, options)
        except JsonLdError as cause:
            raise JsonLdError(
                'Could not expand input before serialization to '
                'RDF.', 'jsonld.RdfError', cause=cause)

        # create node map for default graph (and any named graphs)
        namer = UniqueNamer('_:b')
        node_map = {'@default': {}}
        self._create_node_map(expanded, node_map, '@default', namer)

        # output RDF dataset
        dataset = {}
        for graph_name, graph in sorted(node_map.items()):
            # skip relative IRIs
            if graph_name == '@default' or _is_absolute_iri(graph_name):
                dataset[graph_name] = self._graph_to_rdf(graph, namer, options)

        # convert to output format
        if 'format' in options:
            if options['format'] == 'application/nquads':
                return self.to_nquads(dataset)
            raise JsonLdError(
                'Unknown output format.',
                'jsonld.UnknownFormat', {'format': options['format']})
        return dataset

    def process_context(self, active_ctx, local_ctx, options):
        """
        Processes a local context, retrieving any URLs as necessary, and
        returns a new active context in its callback.

        :param active_ctx: the current active context.
        :param local_ctx: the local context to process.
        :param options: the options to use.
          [documentLoader(url)] the document loader
            (default: _default_document_loader).

        :return: the new active context.
        """
        # return initial context early for None context
        if local_ctx is None:
            return self._get_initial_context(options)

        # set default options
        options = options or {}
        options.setdefault('base', '')
        options.setdefault('documentLoader', _default_document_loader)

        # retrieve URLs in local_ctx
        local_ctx = copy.deepcopy(local_ctx)
        if (_is_string(local_ctx) or (
                _is_object(local_ctx) and '@context' not in local_ctx)):
            local_ctx = {'@context': local_ctx}
        try:
            self._retrieve_context_urls(
                local_ctx, {}, options['documentLoader'], options['base'])
        except BaseException as cause:
            raise JsonLdError(
                'Could not process JSON-LD context.',
                'jsonld.ContextError', cause=cause)

        # process context
        return self._process_context(active_ctx, local_ctx, options)

    def register_rdf_parser(self, content_type, parser):
        """
        Registers a processor-specific RDF parser by content-type.
        Global parsers will no longer be used by this processor.

        :param content_type: the content-type for the parser.
        :param parser(input): the parser function (takes a string as
                 a parameter and returns an RDF dataset).
        """
        if self.rdf_parsers is None:
            self.rdf_parsers = {}
            self.rdf_parsers[content_type] = parser

    def unregister_rdf_parser(self, content_type):
        """
        Unregisters a process-specific RDF parser by content-type.
        If there are no remaining processor-specific parsers, then the global
        parsers will be re-enabled.

        :param content_type: the content-type for the parser.
        """
        if (self.rdf_parsers is not None and
                content_type in self.rdf_parsers):
            del self.rdf_parsers[content_type]
            if len(self.rdf_parsers) == 0:
                self.rdf_parsers = None

    @staticmethod
    def has_property(subject, property):
        """
        Returns True if the given subject has the given property.

        :param subject: the subject to check.
        :param property: the property to look for.

        :return: True if the subject has the given property, False if not.
        """
        if property in subject:
            value = subject[property]
            return not _is_array(value) or len(value) > 0
        return False

    @staticmethod
    def has_value(subject, property, value):
        """
         Determines if the given value is a property of the given subject.

        :param subject: the subject to check.
        :param property: the property to check.
        :param value: the value to check.

        :return: True if the value exists, False if not.
        """
        if JsonLdProcessor.has_property(subject, property):
            val = subject[property]
            is_list = _is_list(val)
            if _is_array(val) or is_list:
                if is_list:
                    val = val['@list']
                for v in val:
                    if JsonLdProcessor.compare_values(value, v):
                        return True
            # avoid matching the set of values with an array value parameter
            elif not _is_array(value):
                return JsonLdProcessor.compare_values(value, val)
        return False

    @staticmethod
    def add_value(subject, property, value, options={}):
        """
        Adds a value to a subject. If the value is an array, all values in the
        array will be added.

        :param subject: the subject to add the value to.
        :param property: the property that relates the value to the subject.
        :param value: the value to add.
        :param [options]: the options to use:
          [propertyIsArray] True if the property is always
            an array, False if not (default: False).
          [allowDuplicate] True to allow duplicates, False not to (uses
            a simple shallow comparison of subject ID or value)
            (default: True).
        """
        options.setdefault('propertyIsArray', False)
        options.setdefault('allowDuplicate', True)

        if _is_array(value):
            if (len(value) == 0 and options['propertyIsArray'] and
                    property not in subject):
                subject[property] = []
            for v in value:
                JsonLdProcessor.add_value(subject, property, v, options)
        elif property in subject:
            # check if subject already has value if duplicates not allowed
            has_value = \
                (not options['allowDuplicate'] and
                 JsonLdProcessor.has_value(subject, property, value))

            # make property an array if value not present or always an array
            if (not _is_array(subject[property]) and
                    (not has_value or options['propertyIsArray'])):
                subject[property] = [subject[property]]

            # add new value
            if not has_value:
                subject[property].append(value)
        else:
            # add new value as set or single value
            subject[property] = (
                [value] if options['propertyIsArray'] else value)

    @staticmethod
    def get_values(subject, property):
        """
        Gets all of the values for a subject's property as an array.

        :param subject: the subject.
        :param property: the property.

        :return: all of the values for a subject's property as an array.
        """
        return JsonLdProcessor.arrayify(subject.get(property) or [])

    @staticmethod
    def remove_property(subject, property):
        """
        Removes a property from a subject.

        :param subject: the subject.
        :param property: the property.
        """
        del subject[property]

    @staticmethod
    def remove_value(subject, property, value, options={}):
        """
        Removes a value from a subject.

        :param subject: the subject.
        :param property: the property that relates the value to the subject.
        :param value: the value to remove.
        :param [options]: the options to use:
          [propertyIsArray]: True if the property is always an array,
            False if not (default: False).
        """
        options.setdefault('propertyIsArray', False)

        # filter out value
        def filter_value(e):
            return not JsonLdProcessor.compare_values(e, value)
        values = JsonLdProcessor.get_values(subject, property)
        values = list(filter(filter_value, values))

        if len(values) == 0:
            JsonLdProcessor.remove_property(subject, property)
        elif len(values) == 1 and not options['propertyIsArray']:
            subject[property] = values[0]
        else:
            subject[property] = values

    @staticmethod
    def compare_values(v1, v2):
        """
        Compares two JSON-LD values for equality. Two JSON-LD values will be
        considered equal if:

        1. They are both primitives of the same type and value.
        2. They are both @values with the same @value, @type, @language,
          and @index, OR
        3. They both have @ids that are the same.

        :param v1: the first value.
        :param v2: the second value.

        :return: True if v1 and v2 are considered equal, False if not.
        """
        # 1. equal primitives
        if not _is_object(v1) and not _is_object(v2) and v1 == v2:
            type1 = type(v1)
            type2 = type(v2)
            if type1 == bool or type2 == bool:
                return type1 == type2
            return True

        # 2. equal @values
        if (_is_value(v1) and _is_value(v2) and
                v1['@value'] == v2['@value'] and
                v1.get('@type') == v2.get('@type') and
                v1.get('@language') == v2.get('@language') and
                v1.get('@index') == v2.get('@index')):
            type1 = type(v1['@value'])
            type2 = type(v2['@value'])
            if type1 == bool or type2 == bool:
                return type1 == type2
            return True

        # 3. equal @ids
        if (_is_object(v1) and '@id' in v1 and
                _is_object(v2) and '@id' in v2):
            return v1['@id'] == v2['@id']

        return False

    @staticmethod
    def get_context_value(ctx, key, type_):
        """
        Gets the value for the given active context key and type, None if none
        is set.

        :param ctx: the active context.
        :param key: the context key.
        :param [type_]: the type of value to get (eg: '@id', '@type'), if not
          specified gets the entire entry for a key, None if not found.

        :return: mixed the value.
        """
        rval = None

        # return None for invalid key
        if key is None:
            return rval

        # get default language
        if type_ == '@language' and type_ in ctx:
            rval = ctx[type_]

        # get specific entry information
        if key in ctx['mappings']:
            entry = ctx['mappings'][key]
            if entry is None:
                return None

            # return whole entry
            if type_ is None:
                rval = entry
            # return entry value for type
            elif type_ in entry:
                rval = entry[type_]

        return rval

    @staticmethod
    def parse_nquads(input_):
        """
        Parses RDF in the form of N-Quads.

        :param input_: the N-Quads input to parse.

        :return: an RDF dataset.
        """
        # define partial regexes
        iri = '(?:<([^:]+:[^>]*)>)'
        bnode = '(_:(?:[A-Za-z][A-Za-z0-9]*))'
        plain = '"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"'
        datatype = '(?:\\^\\^' + iri + ')'
        language = '(?:@([a-z]+(?:-[a-z0-9]+)*))'
        literal = '(?:' + plain + '(?:' + datatype + '|' + language + ')?)'
        ws = '[ \\t]+'
        wso = '[ \\t]*'
        eoln = r'(?:\r\n)|(?:\n)|(?:\r)'
        empty = r'^' + wso + '$'

        # define quad part regexes
        subject = '(?:' + iri + '|' + bnode + ')' + ws
        property = iri + ws
        object = '(?:' + iri + '|' + bnode + '|' + literal + ')' + wso
        graph = '(?:\\.|(?:(?:' + iri + '|' + bnode + ')' + wso + '\\.))'

        # Note: Notice that the graph position does not include literals
        # even though they are specified as a possible value in the
        # N-Quads note (http://sw.deri.org/2008/07/n-quads/). This is
        # intentional, as literals in that position are not supported by the
        # RDF data model or the JSON-LD data model.
        # See: https://github.com/digitalbazaar/pyld/pull/19

        # full quad regex
        quad = r'^' + wso + subject + property + object + graph + wso + '$'

        # build RDF dataset
        dataset = {}

        # split N-Quad input into lines
        lines = re.split(eoln, input_)
        line_number = 0
        for line in lines:
            line_number += 1

            # skip empty lines
            if re.search(empty, line) is not None:
                continue

            # parse quad
            ldmatch = re.search(quad, line)
            if ldmatch is None:
                raise JsonLdError(
                    'Error while parsing N-Quads invalid quad.',
                    'jsonld.ParseError', {'line': line_number})
            ldmatch = ldmatch.groups()

            # create RDF triple
            triple = {'subject': {}, 'predicate': {}, 'object': {}}

            # get subject
            if ldmatch[0] is not None:
                triple['subject'] = {'type': 'IRI', 'value': ldmatch[0]}
            else:
                triple['subject'] = {'type': 'blank node', 'value': ldmatch[1]}

            # get predicate
            triple['predicate'] = {'type': 'IRI', 'value': ldmatch[2]}

            # get object
            if ldmatch[3] is not None:
                triple['object'] = {'type': 'IRI', 'value': ldmatch[3]}
            elif ldmatch[4] is not None:
                triple['object'] = {'type': 'blank node', 'value': ldmatch[4]}
            else:
                triple['object'] = {'type': 'literal'}
                replacements = {
                    '\\"': '\"',
                    '\\t': '\t',
                    '\\n': '\n',
                    '\\r': '\r',
                    '\\\\': '\\'
                }
                unescaped = ldmatch[5]
                for ldmatch, repl in replacements.items():
                    unescaped = unescaped.replace(ldmatch, repl)
                if ldmatch[6] is not None:
                    triple['object']['datatype'] = ldmatch[6]
                elif ldmatch[7] is not None:
                    triple['object']['datatype'] = RDF_LANGSTRING
                    triple['object']['language'] = ldmatch[7]
                else:
                    triple['object']['datatype'] = XSD_STRING
                triple['object']['value'] = unescaped

            # get graph name ('@default' is used for the default graph)
            name = '@default'
            if ldmatch[8] is not None:
                name = ldmatch[8]
            elif ldmatch[9] is not None:
                name = ldmatch[9]

            # initialize graph in dataset
            if name not in dataset:
                dataset[name] = [triple]
            # add triple if unique to its graph
            else:
                unique = True
                triples = dataset[name]
                for t in dataset[name]:
                    if JsonLdProcessor._compare_rdf_triples(t, triple):
                        unique = False
                        break
                if unique:
                    triples.append(triple)

        return dataset

    @staticmethod
    def to_nquads(dataset):
        """
        Converts an RDF dataset to N-Quads.

        :param dataset: the RDF dataset to convert.

        :return: the N-Quads string.
        """
        quads = []
        for graph_name, triples in dataset.items():
            for triple in triples:
                if graph_name == '@default':
                    graph_name = None
                quads.append(JsonLdProcessor.to_nquad(triple, graph_name))
        quads.sort()
        return ''.join(quads)

    @staticmethod
    def to_nquad(triple, graph_name, bnode=None):
        """
        Converts an RDF triple and graph name to an N-Quad string (a single
        quad).

        :param triple: the RDF triple to convert.
        :param graph_name: the name of the graph containing the triple, None
          for the default graph.
        :param bnode: the bnode the quad is mapped to (optional, for
          use during normalization only).

        :return: the N-Quad string.
        """
        s = triple['subject']
        p = triple['predicate']
        o = triple['object']
        g = graph_name

        quad = ''

        # subject is an IRI
        if s['type'] == 'IRI':
            quad += '<' + s['value'] + '>'
        # bnode normalization mode
        elif bnode is not None:
            quad += '_:a' if s['value'] == bnode else '_:z'
        # bnode normal mode
        else:
            quad += s['value']
        quad += ' '

        # property is an IRI
        if p['type'] == 'IRI':
            quad += '<' + p['value'] + '>'
        # FIXME: TBD what to do with bnode predicates during normalization
        # bnode normalization mode
        elif bnode is not None:
            quad += '_:p'
        # bnode normal mode
        else:
            quad += p['value']
        quad += ' '

        # object is IRI, bnode, or literal
        if o['type'] == 'IRI':
            quad += '<' + o['value'] + '>'
        elif (o['type'] == 'blank node'):
            # normalization mode
            if bnode is not None:
                quad += '_:a' if o['value'] == bnode else '_:z'
            # normal mode
            else:
                quad += o['value']
        else:
            replacements = {
                '\\': '\\\\',
                '\t': '\\t',
                '\n': '\\n',
                '\r': '\\r',
                '\"': '\\"'
            }
            escaped = o['value']
            for ldmatch, repl in replacements.items():
                escaped = escaped.replace(ldmatch, repl)
            quad += '"' + escaped + '"'
            if o['datatype'] == RDF_LANGSTRING:
                if o['language']:
                    quad += '@' + o['language']
            elif o['datatype'] != XSD_STRING:
                quad += '^^<' + o['datatype'] + '>'

        # graph
        if g is not None:
            if not g.startswith('_:'):
                quad += ' <' + g + '>'
            elif bnode is not None:
                quad += ' _:g'
            else:
                quad += ' ' + g

        quad += ' .\n'
        return quad

    @staticmethod
    def arrayify(value):
        """
        If value is an array, returns value, otherwise returns an array
        containing value as the only element.

        :param value: the value.

        :return: an array.
        """
        return value if _is_array(value) else [value]

    @staticmethod
    def _compare_rdf_triples(t1, t2):
        """
        Compares two RDF triples for equality.

        :param t1: the first triple.
        :param t2: the second triple.

        :return: True if the triples are the same, False if not.
        """
        for attr in ['subject', 'predicate', 'object']:
            if t1[attr]['type'] != t2[attr]['type'] or \
               t1[attr]['value'] != t2[attr]['value']:
                return False

        if t1['object'].get('language') != t2['object'].get('language'):
            return False
        if t1['object'].get('datatype') != t2['object'].get('datatype'):
            return False

        return True

    def _compact(self, active_ctx, active_property, element, options):
        """
        Recursively compacts an element using the given active context. All
        values must be in expanded form before this method is called.

        :param active_ctx: the active context to use.
        :param active_property: the compacted property with the element to
          compact, None for none.
        :param element: the element to compact.
        :param options: the compaction options.

        :return: the compacted value.
        """
        # recursively compact array
        if _is_array(element):
            rval = []
            for e in element:
                # compact, dropping any None values
                e = self._compact(active_ctx, active_property, e, options)
                if e is not None:
                    rval.append(e)
            if options['compactArrays'] and len(rval) == 1:
                # use single element if no container is specified
                container = JsonLdProcessor.get_context_value(
                    active_ctx, active_property, '@container')
                if container is None:
                    rval = rval[0]
            return rval

        # recursively compact object
        if _is_object(element):
            if options['link'] and '@id' in element and \
               element['@id'] in options['link']:
                # check for a linked element to reuse
                linked = options['link'][element['@id']]
                for link in linked:
                    if link['expanded'] == element:
                        return link['compacted']

            # do value compaction on @values and subject references
            if _is_value(element) or _is_subject_reference(element):
                rval = self._compact_value(
                    active_ctx, active_property, element)
                if options['link'] and _is_subject_reference(element):
                    # store linked element
                    options['link'].setdefault(element['@id'], []).append(
                        {'expanded': element, 'compacted': rval})
                return rval

            # FIXME: avoid misuse of active property as an expanded property?
            inside_reverse = (active_property == '@reverse')

            rval = {}

            if options['link'] and '@id' in element:
                # store linked element
                options['link'].setdefault(element['@id'], []).append(
                    {'expanded': element, 'compacted': rval})

            # recursively process element keys in order
            for expanded_property, expanded_value in sorted(element.items()):
                # compact @id and @type(s)
                if expanded_property == '@id' or expanded_property == '@type':
                    # compact single @id
                    if _is_string(expanded_value):
                        compacted_value = self._compact_iri(
                            active_ctx, expanded_value,
                            vocab=(expanded_property == '@type'))
                    # expanded value must be a @type array
                    else:
                        compacted_value = []
                        for ev in expanded_value:
                            compacted_value.append(self._compact_iri(
                                active_ctx, ev, vocab=True))

                    # use keyword alias and add value
                    alias = self._compact_iri(active_ctx, expanded_property)
                    is_array = (_is_array(compacted_value) and
                                len(compacted_value) == 0)
                    JsonLdProcessor.add_value(
                        rval, alias, compacted_value,
                        {'propertyIsArray': is_array})
                    continue

                # handle @reverse
                if expanded_property == '@reverse':
                    # recursively compact expanded value
                    compacted_value = self._compact(
                        active_ctx, '@reverse', expanded_value, options)

                    # handle double-reversed properties
                    for compacted_property, value in \
                            list(compacted_value.items()):
                        mapping = active_ctx['mappings'].get(
                            compacted_property)
                        if mapping and mapping['reverse']:
                            container = JsonLdProcessor.get_context_value(
                                active_ctx, compacted_property, '@container')
                            use_array = (container == '@set' or
                                         not options['compactArrays'])
                            JsonLdProcessor.add_value(
                                rval, compacted_property, value,
                                {'propertyIsArray': use_array})
                            del compacted_value[compacted_property]

                    if len(compacted_value.keys()) > 0:
                        # use keyword alias and add value
                        alias = self._compact_iri(
                            active_ctx, expanded_property)
                        JsonLdProcessor.add_value(rval, alias, compacted_value)

                    continue

                # handle @index
                if expanded_property == '@index':
                    # drop @index if inside an @index container
                    container = JsonLdProcessor.get_context_value(
                        active_ctx, active_property, '@container')
                    if container == '@index':
                        continue

                    # use keyword alias and add value
                    alias = self._compact_iri(active_ctx, expanded_property)
                    JsonLdProcessor.add_value(rval, alias, expanded_value)
                    continue

                # skip array processing for keywords that aren't
                # @graph or @list
                if expanded_property != '@graph' and \
                   expanded_property != '@list' and \
                   _is_keyword(expanded_property):
                    # use keyword alias and add value as is
                    alias = self._compact_iri(active_ctx, expanded_property)
                    JsonLdProcessor.add_value(rval, alias, expanded_value)
                    continue

                # Note: expanded value must be an array due to expansion
                # algorithm.

                # preserve empty arrays
                if len(expanded_value) == 0:
                    item_active_property = self._compact_iri(
                        active_ctx, expanded_property, expanded_value,
                        vocab=True, reverse=inside_reverse)
                    JsonLdProcessor.add_value(
                        rval, item_active_property, [],
                        {'propertyIsArray': True})

                # recusively process array values
                for expanded_item in expanded_value:
                    # compact property and get container type
                    item_active_property = self._compact_iri(
                        active_ctx, expanded_property, expanded_item,
                        vocab=True, reverse=inside_reverse)
                    container = JsonLdProcessor.get_context_value(
                        active_ctx, item_active_property, '@container')

                    # get @list value if appropriate
                    is_list = _is_list(expanded_item)
                    list_ = None
                    if is_list:
                        list_ = expanded_item['@list']

                    # recursively compact expanded item
                    compacted_item = self._compact(
                        active_ctx, item_active_property,
                        list_ if is_list else expanded_item, options)

                    # handle @list
                    if is_list:
                        # ensure @list is an array
                        compacted_item = JsonLdProcessor.arrayify(
                            compacted_item)

                        if container != '@list':
                            # wrap using @list alias
                            wrapper = {}
                            wrapper[self._compact_iri(
                                active_ctx, '@list')] = compacted_item
                            compacted_item = wrapper

                            # include @index from expanded @list, if any
                            if '@index' in expanded_item:
                                alias = self._compact_iri(active_ctx, '@index')
                                compacted_item[alias] = (
                                    expanded_item['@index'])
                        # can't use @list container for more than 1 list
                        elif item_active_property in rval:
                            raise JsonLdError(
                                'JSON-LD compact error; property has a '
                                '"@list" @container rule but there is more '
                                'than a single @list that matches the '
                                'compacted term in the document. Compaction '
                                'might mix unwanted items into the list.',
                                'jsonld.SyntaxError',
                                code='compaction to list of lists')

                    # handle language and index maps
                    if container == '@language' or container == '@index':
                        # get or create the map object
                        map_object = rval.setdefault(item_active_property, {})

                        # if container is a language map, simplify compacted
                        # value to a simple string
                        if (container == '@language' and
                                _is_value(compacted_item)):
                            compacted_item = compacted_item['@value']

                        # add compact value to map object using key from
                        # expanded value based on the container type
                        JsonLdProcessor.add_value(
                            map_object, expanded_item[container],
                            compacted_item)
                    else:
                        # use an array if compactArrays flag is false,
                        # @container is @set or @list, value is an empty
                        # array, or key is @graph
                        is_array = (not options['compactArrays'] or
                                    container == '@set' or
                                    container == '@list' or
                                    (_is_array(compacted_item) and
                                     len(compacted_item) == 0) or
                                    expanded_property == '@list' or
                                    expanded_property == '@graph')

                        # add compact value
                        JsonLdProcessor.add_value(
                            rval, item_active_property, compacted_item,
                            {'propertyIsArray': is_array})

            return rval

        # only primitives remain which are already compact
        return element

    def _expand(
            self, active_ctx, active_property, element, options, inside_list):
        """
        Recursively expands an element using the given context. Any context in
        the element will be removed. All context URLs must have been retrieved
        before calling this method.

        :param active_ctx: the context to use.
        :param active_property: the property for the element, None for none.
        :param element: the element to expand.
        :param options: the expansion options.
        :param inside_list: True if the property is a list, False if not.

        :return: the expanded value.
        """
        # nothing to expand
        if element is None:
            return element

        # recursively expand array
        if _is_array(element):
            rval = []
            container = JsonLdProcessor.get_context_value(
                active_ctx, active_property, '@container')
            inside_list = inside_list or container == '@list'
            for e in element:
                # expand element
                e = self._expand(
                    active_ctx, active_property, e, options, inside_list)
                if inside_list and (_is_array(e) or _is_list(e)):
                    # lists of lists are illegal
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; lists of lists are not '
                        'permitted.', 'jsonld.SyntaxError',
                        code='list of lists')
                # drop None values
                if e is not None:
                    if _is_array(e):
                        rval.extend(e)
                    else:
                        rval.append(e)
            return rval

        # handle scalars
        if not _is_object(element):
            # drop free-floating scalars that are not in lists
            if (not inside_list and (active_property is None or
                self._expand_iri(
                    active_ctx, active_property, vocab=True) == '@graph')):
                return None

            # expand element according to value expansion rules
            return self._expand_value(active_ctx, active_property, element)

        # recursively expand object
        # if element has a context, process it
        if '@context' in element:
            active_ctx = self._process_context(
                active_ctx, element['@context'], options)

        # expand the active property
        expanded_active_property = self._expand_iri(
            active_ctx, active_property, vocab=True)

        rval = {}
        for key, value in sorted(element.items()):
            if key == '@context':
                continue

            # expand key to IRI
            expanded_property = self._expand_iri(
                active_ctx, key, vocab=True)

            # drop non-absolute IRI keys that aren't keywords
            if (expanded_property is None or not
                (_is_absolute_iri(expanded_property) or
                 _is_keyword(expanded_property))):
                continue

            if _is_keyword(expanded_property):
                if expanded_active_property == '@reverse':
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; a keyword cannot be used as '
                        'a @reverse property.',
                        'jsonld.SyntaxError', {'value': value},
                        code='invalid reverse property map')
                if expanded_property in rval:
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; colliding keywords detected.',
                        'jsonld.SyntaxError', {'keyword': expanded_property},
                        code='colliding keywords')

            # syntax error if @id is not a string
            if expanded_property == '@id' and not _is_string(value):
                if not options.get('isFrame'):
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; "@id" value must a string.',
                        'jsonld.SyntaxError', {'value': value},
                        code='invalid @id value')
                if not _is_object(value):
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; "@id" value must be a '
                        'string or an object.', 'jsonld.SyntaxError',
                        {'value': value}, code='invalid @id value')

            if expanded_property == '@type':
                _validate_type_value(value)

            # @graph must be an array or an object
            if (expanded_property == '@graph' and
                    not (_is_object(value) or _is_array(value))):
                raise JsonLdError(
                    'Invalid JSON-LD syntax; "@graph" must not be an '
                    'object or an array.', 'jsonld.SyntaxError',
                    {'value': value}, code='invalid @graph value')

            # @value must not be an object or an array
            if (expanded_property == '@value' and
                    (_is_object(value) or _is_array(value))):
                raise JsonLdError(
                    'Invalid JSON-LD syntax; "@value" value must not be an '
                    'object or an array.', 'jsonld.SyntaxError',
                    {'value': value}, code='invalid value object value')

            # @language must be a string
            if expanded_property == '@language':
                if value is None:
                    # drop null @language values, they expand as if they
                    # didn't exist
                    continue
                if not _is_string(value):
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; "@language" value must be '
                        'a string.', 'jsonld.SyntaxError', {'value': value},
                        code='invalid language-tagged string')
                # ensure language value is lowercase
                value = value.lower()

            # @index must be a string
            if expanded_property == '@index' and not _is_string(value):
                raise JsonLdError(
                    'Invalid JSON-LD syntax; "@index" value must be '
                    'a string.', 'jsonld.SyntaxError', {'value': value},
                    code='invalid @index value')

            # reverse must be an object
            if expanded_property == '@reverse':
                if not _is_object(value):
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; "@reverse" value must be '
                        'an object.', 'jsonld.SyntaxError', {'value': value},
                        code='invalid @reverse value')

                expanded_value = self._expand(
                    active_ctx, '@reverse', value, options, inside_list)

                # properties double-reversed
                if '@reverse' in expanded_value:
                    for rprop, rvalue in expanded_value['@reverse'].items():
                        JsonLdProcessor.add_value(
                            rval, rprop, rvalue,
                            {'propertyIsArray': True})

                # merge in all reversed properties
                reverse_map = rval.get('@reverse')
                for property, items in expanded_value.items():
                    if property == '@reverse':
                        continue
                    if reverse_map is None:
                        reverse_map = rval['@reverse'] = {}
                    JsonLdProcessor.add_value(
                        reverse_map, property, [],
                        {'propertyIsArray': True})
                    for item in items:
                        if _is_value(item) or _is_list(item):
                            raise JsonLdError(
                                'Invalid JSON-LD syntax; "@reverse" '
                                'value must not be an @value or an @list',
                                'jsonld.SyntaxError',
                                {'value': expanded_value},
                                code='invalid reverse property value')
                        JsonLdProcessor.add_value(
                            reverse_map, property, item,
                            {'propertyIsArray': True})

                continue

            container = JsonLdProcessor.get_context_value(
                active_ctx, key, '@container')

            # handle language map container (skip if value is not an object)
            if container == '@language' and _is_object(value):
                expanded_value = self._expand_language_map(value)
            # handle index container (skip if value is not an object)
            elif container == '@index' and _is_object(value):
                def expand_index_map(active_property):
                    rval = []
                    for k, v in sorted(value.items()):
                        v = self._expand(
                            active_ctx, active_property,
                            JsonLdProcessor.arrayify(v),
                            options, inside_list=False)
                        for item in v:
                            item.setdefault('@index', k)
                            rval.append(item)
                    return rval
                expanded_value = expand_index_map(key)
            else:
                # recurse into @list or @set
                is_list = (expanded_property == '@list')
                if is_list or expanded_property == '@set':
                    next_active_property = active_property
                    if is_list and expanded_active_property == '@graph':
                        next_active_property = None
                    expanded_value = self._expand(
                        active_ctx, next_active_property, value, options,
                        is_list)
                    if is_list and _is_list(expanded_value):
                        raise JsonLdError(
                            'Invalid JSON-LD syntax; lists of lists are '
                            'not permitted.', 'jsonld.SyntaxError',
                            code='list of lists')
                else:
                    # recursively expand value w/key as new active property
                    expanded_value = self._expand(
                        active_ctx, key, value, options, inside_list=False)

            # drop None values if property is not @value (dropped below)
            if expanded_value is None and expanded_property != '@value':
                continue

            # convert expanded value to @list if container specifies it
            if (expanded_property != '@list' and not _is_list(expanded_value)
                    and container == '@list'):
                # ensure expanded value is an array
                expanded_value = {
                    '@list': JsonLdProcessor.arrayify(expanded_value)
                }

            # merge in reverse properties
            mapping = active_ctx['mappings'].get(key)
            if mapping and mapping['reverse']:
                reverse_map = rval.setdefault('@reverse', {})
                expanded_value = JsonLdProcessor.arrayify(expanded_value)
                for item in expanded_value:
                    if _is_value(item) or _is_list(item):
                        raise JsonLdError(
                            'Invalid JSON-LD syntax; "@reverse" value must '
                            'not be an @value or an @list.',
                            'jsonld.SyntaxError', {'value': expanded_value},
                            code='invalid reverse property value')
                    JsonLdProcessor.add_value(
                        reverse_map, expanded_property, item,
                        {'propertyIsArray': True})
                continue

            # add value for property, use an array exception for certain
            # key words
            use_array = (expanded_property not in ['@index', '@id', '@type',
                                                   '@value', '@language'])
            JsonLdProcessor.add_value(
                rval, expanded_property, expanded_value,
                {'propertyIsArray': use_array})

        # get property count on expanded output
        count = len(rval)

        if '@value' in rval:
            # @value must only have @language or @type
            if '@type' in rval and '@language' in rval:
                raise JsonLdError(
                    'Invalid JSON-LD syntax; an element containing '
                    '"@value" may not contain both "@type" and "@language".',
                    'jsonld.SyntaxError', {'element': rval},
                    code='invalid value object')
            valid_count = count - 1
            if '@type' in rval:
                valid_count -= 1
            if '@index' in rval:
                valid_count -= 1
            if '@language' in rval:
                valid_count -= 1
            if valid_count != 0:
                raise JsonLdError(
                    'Invalid JSON-LD syntax; an element containing "@value" '
                    'may only have an "@index" property and at most one other '
                    'property which can be "@type" or "@language".',
                    'jsonld.SyntaxError', {'element': rval},
                    code='invalid value object')
            # drop None @values
            if rval['@value'] is None:
                rval = None
            # if @language is present, @value must be a string
            elif '@language' in rval and not _is_string(rval['@value']):
                raise JsonLdError(
                    'Invalid JSON-LD syntax; only strings may be '
                    'language-tagged.', 'jsonld.SyntaxError',
                    {'element': rval}, code='invalid language-tagged value')
            elif ('@type' in rval and (not _is_absolute_iri(rval['@type']) or
                                       rval['@type'].startswith('_:'))):
                raise JsonLdError(
                    'Invalid JSON-LD syntax; an element containing "@value" '
                    'and "@type" must have an absolute IRI for the value '
                    'of "@type".', 'jsonld.SyntaxError', {'element': rval},
                    code='invalid typed value')
        # convert @type to an array
        elif '@type' in rval and not _is_array(rval['@type']):
            rval['@type'] = [rval['@type']]
        # handle @set and @list
        elif '@set' in rval or '@list' in rval:
            if count > 1 and not (count == 2 and '@index' in rval):
                raise JsonLdError(
                    'Invalid JSON-LD syntax; if an element has the '
                    'property "@set" or "@list", then it can have at most '
                    'one other property, which is "@index".',
                    'jsonld.SyntaxError', {'element': rval},
                    code='invalid set or list object')
            # optimize away @set
            if '@set' in rval:
                rval = rval['@set']
                count = len(rval)
        # drop objects with only @language
        elif count == 1 and '@language' in rval:
            rval = None

        # drop certain top-level objects that do not occur in lists
        if (_is_object(rval) and not options.get('keepFreeFloatingNodes') and
            not inside_list and (active_property is None or
                                 expanded_active_property == '@graph')):
            # drop empty object or top-level @value/@list,
            # or object with only @id
            if (count == 0 or '@value' in rval or '@list' in rval or
                    (count == 1 and '@id' in rval)):
                rval = None

        return rval

    def _flatten(self, input):
        """
        Performs JSON-LD flattening.

        :param input_: the expanded JSON-LD to flatten.

        :return: the flattened JSON-LD output.
        """
        # produce a map of all subjects and name each bnode
        namer = UniqueNamer('_:b')
        graphs = {'@default': {}}
        self._create_node_map(input, graphs, '@default', namer)

        # add all non-default graphs to default graph
        default_graph = graphs['@default']
        for graph_name, node_map in graphs.items():
            if graph_name == '@default':
                continue
            graph_subject = default_graph.setdefault(
                graph_name, {'@id': graph_name, '@graph': []})
            graph_subject.setdefault('@graph', []).extend(
                [v for k, v in sorted(node_map.items())
                    if not _is_subject_reference(v)])

        # produce flattened output
        return [value for key, value in sorted(default_graph.items())
                if not _is_subject_reference(value)]

    def _frame(self, input_, frame, options):
        """
        Performs JSON-LD framing.

        :param input_: the expanded JSON-LD to frame.
        :param frame: the expanded JSON-LD frame to use.
        :param options: the framing options.

        :return: the framed output.
        """
        # create framing state
        state = {
            'options': options,
            'graphs': {'@default': {}, '@merged': {}},
            'subjectStack': [],
            'link': {}
        }

        # produce a map of all graphs and name each bnode
        # FIXME: currently uses subjects from @merged graph only
        namer = UniqueNamer('_:b')
        self._create_node_map(input_, state['graphs'], '@merged', namer)
        state['subjects'] = state['graphs']['@merged']

        # frame the subjects
        framed = []
        self._match_frame(
            state, sorted(state['subjects'].keys()), frame, framed, None)
        return framed

    def _normalize(self, dataset, options):
        """
        Performs RDF normalization on the given RDF dataset.

        :param dataset: the RDF dataset to normalize.
        :param options: the normalization options.

        :return: the normalized output.
        """
        # create quads and map bnodes to their associated quads
        quads = []
        bnodes = {}
        for graph_name, triples in dataset.items():
            if graph_name == '@default':
                graph_name = None
            for triple in triples:
                quad = triple
                if graph_name is not None:
                    if graph_name.startswith('_:'):
                        quad['name'] = {'type': 'blank node'}
                    else:
                        quad['name'] = {'type': 'IRI'}
                    quad['name']['value'] = graph_name
                quads.append(quad)

                for attr in ['subject', 'object', 'name']:
                    if attr in quad and quad[attr]['type'] == 'blank node':
                        id_ = quad[attr]['value']
                        bnodes.setdefault(id_, {}).setdefault(
                            'quads', []).append(quad)

        # mapping complete, start canonical naming
        namer = UniqueNamer('_:c14n')

        # continue to hash bnode quads while bnodes are assigned names
        unnamed = None
        next_unnamed = bnodes.keys()
        duplicates = None
        while True:
            unnamed = next_unnamed
            next_unnamed = []
            duplicates = {}
            unique = {}
            for bnode in unnamed:
                # hash quads for each unnamed bnode
                hash = self._hash_quads(bnode, bnodes)

                # store hash as unique or a duplicate
                if hash in duplicates:
                    duplicates[hash].append(bnode)
                    next_unnamed.append(bnode)
                elif hash in unique:
                    duplicates[hash] = [unique[hash], bnode]
                    next_unnamed.append(unique[hash])
                    next_unnamed.append(bnode)
                    del unique[hash]
                else:
                    unique[hash] = bnode

            # name unique bnodes in sorted hash order
            for hash, bnode in sorted(unique.items()):
                namer.get_name(bnode)

            # done when no more bnodes named
            if len(unnamed) == len(next_unnamed):
                break

        # enumerate duplicate hash groups in sorted order
        for hash, group in sorted(duplicates.items()):
            # process group
            results = []
            for bnode in group:
                # skip already-named bnodes
                if namer.is_named(bnode):
                    continue

                # hash bnode paths
                path_namer = UniqueNamer('_:b')
                path_namer.get_name(bnode)
                bnode_path = None
                try:
                    bnode_path = self._hash_paths(
                        bnode, bnodes, namer, path_namer)
                except BaseException:
                    print('WARN: jsonld bnode_path failed')
                if bnode_path:
                    results.append(bnode_path)

            # name bnodes in hash order
            cmp_hashes = cmp_to_key(lambda x, y: cmp(x['hash'], y['hash']))
            for result in sorted(results, key=cmp_hashes):
                # name all bnodes in path namer in key-entry order
                for bnode in result['pathNamer'].order:
                    namer.get_name(bnode)

        # create normalized array
        normalized = []

        # Note: At this point all bnodes in the set of RDF quads have been
        # assigned canonical names, which have been stored in the 'namer'
        # object. Here each quad is updated by assigning each of its bnodes its
        # new name via the 'namer' object.

        # update bnode names in each quad and serialize
        for quad in quads:
            for attr in ['subject', 'object', 'name']:
                if (attr in quad and
                        quad[attr]['type'] == 'blank node' and
                        not quad[attr]['value'].startswith('_:c14n')):
                    quad[attr]['value'] = namer.get_name(quad[attr]['value'])
            normalized.append(JsonLdProcessor.to_nquad(
                quad, quad['name']['value'] if 'name' in quad else None))

        # sort normalized output
        normalized.sort()

        # handle output format
        if 'format' in options:
            if options['format'] == 'application/nquads':
                return ''.join(normalized)
            raise JsonLdError(
                'Unknown output format.',
                'jsonld.UnknownFormat', {'format': options['format']})

        # return parsed RDF dataset
        return JsonLdProcessor.parse_nquads(''.join(normalized))

    def _from_rdf(self, dataset, options):
        """
        Converts an RDF dataset to JSON-LD.

        :param dataset: the RDF dataset.
        :param options: the RDF serialization options.

        :return: the JSON-LD output.
        """
        default_graph = {}
        graph_map = {'@default': default_graph}
        referenced_once = {}

        for name, graph in dataset.items():
            graph_map.setdefault(name, {})
            if name != '@default' and name not in default_graph:
                default_graph[name] = {'@id': name}
            node_map = graph_map[name]
            for triple in graph:
                # get subject, predicate, object
                s = triple['subject']['value']
                p = triple['predicate']['value']
                o = triple['object']

                node = node_map.setdefault(s, {'@id': s})

                object_is_id = (o['type'] == 'IRI' or
                                o['type'] == 'blank node')
                if object_is_id and o['value'] not in node_map:
                    node_map[o['value']] = {'@id': o['value']}

                if (p == RDF_TYPE and not options.get('useRdfType', False) and
                        object_is_id):
                    JsonLdProcessor.add_value(
                        node, '@type', o['value'], {'propertyIsArray': True})
                    continue

                value = self._rdf_to_object(o, options['useNativeTypes'])
                JsonLdProcessor.add_value(
                    node, p, value, {'propertyIsArray': True})

                # object may be an RDF list/partial list node but we
                # can't know easily until all triples are read
                if object_is_id:
                    # track rdf:nil uniquely per graph
                    if o['value'] == RDF_NIL:
                        object = node_map[o['value']]
                        if 'usages' not in object:
                            object['usages'] = []
                        object['usages'].append({
                            'node': node,
                            'property': p,
                            'value': value
                        })
                    # object referenced more than once
                    elif o['value'] in referenced_once:
                        referenced_once[o['value']] = False
                    # track single reference
                    else:
                        referenced_once[o['value']] = {
                            'node': node,
                            'property': p,
                            'value': value
                        }

        # convert linked lists to @list arrays
        for name, graph_object in graph_map.items():
            # no @lists to be converted, continue
            if RDF_NIL not in graph_object:
                continue

            # iterate backwards through each RDF list
            nil = graph_object[RDF_NIL]
            for usage in nil['usages']:
                node = usage['node']
                property = usage['property']
                head = usage['value']
                list_ = []
                list_nodes = []

                # ensure node is a well-formed list node; it must:
                # 1. Be referenced only once.
                # 2. Have an array for rdf:first that has 1 item.
                # 3. Have an array for rdf:rest that has 1 item
                # 4. Have no keys other than: @id, rdf:first, rdf:rest
                #   and, optionally, @type where the value is rdf:List.
                node_key_count = len(node.keys())
                while (property == RDF_REST and
                       _is_object(referenced_once.get(node['@id'])) and
                       _is_array(node[RDF_FIRST]) and
                       len(node[RDF_FIRST]) == 1 and
                       _is_array(node[RDF_REST]) and
                       len(node[RDF_REST]) == 1 and
                       (node_key_count == 3 or
                        (node_key_count == 4 and
                         _is_array(node.get('@type')) and
                         len(node['@type']) == 1 and
                         node['@type'][0] == RDF_LIST))):
                    list_.append(node[RDF_FIRST][0])
                    list_nodes.append(node['@id'])

                    # get next node, moving backwards through list
                    usage = referenced_once[node['@id']]
                    node = usage['node']
                    property = usage['property']
                    head = usage['value']
                    node_key_count = len(node.keys())

                    # if node is not a blank node, then list head found
                    if not node['@id'].startswith('_:'):
                        break

                # the list is nested in another list
                if property == RDF_FIRST:
                    # empty list
                    if node['@id'] == RDF_NIL:
                        # can't convert rdf:nil to a @list object because it
                        # would result in a list of lists which isn't supported
                        continue

                    # preserve list head
                    head = graph_object[head['@id']][RDF_REST][0]
                    list_.pop()
                    list_nodes.pop()

                # transform list into @list object
                del head['@id']
                list_.reverse()
                head['@list'] = list_
                for node in list_nodes:
                    graph_object.pop(node, None)

            nil.pop('usages', None)

        result = []
        for subject, node in sorted(default_graph.items()):
            if subject in graph_map:
                graph = node['@graph'] = []
                for s, n in sorted(graph_map[subject].items()):
                    # only add full subjects to top-level
                    if not _is_subject_reference(n):
                        graph.append(n)
            # only add full subjects to top-level
            if not _is_subject_reference(node):
                result.append(node)

        return result

    def _process_context(self, active_ctx, local_ctx, options):
        """
        Processes a local context and returns a new active context.

        :param active_ctx: the current active context.
        :param local_ctx: the local context to process.
        :param options: the context processing options.

        :return: the new active context.
        """
        global _cache

        # normalize local context to an array
        if _is_object(local_ctx) and _is_array(local_ctx.get('@context')):
            local_ctx = local_ctx['@context']
        ctxs = JsonLdProcessor.arrayify(local_ctx)

        # no contexts in array, clone existing context
        if len(ctxs) == 0:
            return self._clone_active_context(active_ctx)

        # process each context in order, update active context on each
        # iteration to ensure proper caching
        rval = active_ctx
        for ctx in ctxs:
            # reset to initial context
            if ctx is None:
                rval = active_ctx = self._get_initial_context(options)
                continue

            # dereference @context key if present
            if _is_object(ctx) and '@context' in ctx:
                ctx = ctx['@context']

            # context must be an object now, all URLs retrieved prior to call
            if not _is_object(ctx):
                raise JsonLdError(
                    'Invalid JSON-LD syntax; @context must be an object.',
                    'jsonld.SyntaxError', {'context': ctx},
                    code='invalid local context')

            # get context from cache if available
            if _cache.get('activeCtx') is not None:
                cached = _cache['activeCtx'].get(active_ctx, ctx)
                if cached:
                    rval = active_ctx = cached
                    continue

            # update active context and clone new one before updating
            active_ctx = rval
            rval = self._clone_active_context(active_ctx)

            # define context mappings for keys in local context
            defined = {}

            # handle @base
            if '@base' in ctx:
                base = ctx['@base']
                if base is None:
                    base = None
                elif not _is_string(base):
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; the value of "@base" in a '
                        '@context must be a string or null.',
                        'jsonld.SyntaxError', {'context': ctx},
                        code='invalid base IRI')
                elif base != '' and not _is_absolute_iri(base):
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; the value of "@base" in a '
                        '@context must be an absolute IRI or the empty '
                        'string.', 'jsonld.SyntaxError', {'context': ctx},
                        code='invalid base IRI')
                rval['@base'] = base
                defined['@base'] = True

            # handle @vocab
            if '@vocab' in ctx:
                value = ctx['@vocab']
                if value is None:
                    del rval['@vocab']
                elif not _is_string(value):
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; the value of "@vocab" in a '
                        '@context must be a string or null.',
                        'jsonld.SyntaxError', {'context': ctx},
                        code='invalid vocab mapping')
                elif not _is_absolute_iri(value):
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; the value of "@vocab" in a '
                        '@context must be an absolute IRI.',
                        'jsonld.SyntaxError', {'context': ctx},
                        code='invalid vocab mapping')
                else:
                    rval['@vocab'] = value
                defined['@vocab'] = True

            # handle @language
            if '@language' in ctx:
                value = ctx['@language']
                if value is None:
                    del rval['@language']
                elif not _is_string(value):
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; the value of "@language" in '
                        'a @context must be a string or null.',
                        'jsonld.SyntaxError', {'context': ctx},
                        code='invalid default language')
                else:
                    rval['@language'] = value.lower()
                defined['@language'] = True

            # process all other keys
            for k, v in ctx.items():
                self._create_term_definition(rval, ctx, k, defined)

            # cache result
            if _cache.get('activeCtx') is not None:
                _cache.get('activeCtx').set(active_ctx, ctx, rval)

        return rval

    def _expand_language_map(self, language_map):
        """
        Expands a language map.

        :param language_map: the language map to expand.

        :return: the expanded language map.
        """
        rval = []
        for key, values in sorted(language_map.items()):
            values = JsonLdProcessor.arrayify(values)
            for item in values:
                if not _is_string(item):
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; language map values must be '
                        'strings.', 'jsonld.SyntaxError',
                        {'languageMap': language_map},
                        code='invalid language map value')
                rval.append({'@value': item, '@language': key.lower()})
        return rval

    def _expand_value(self, active_ctx, active_property, value):
        """
        Expands the given value by using the coercion and keyword rules in the
        given context.

        :param active_ctx: the active context to use.
        :param active_property: the property the value is associated with.
        :param value: the value to expand.

        :return: the expanded value.
        """
        # nothing to expand
        if value is None:
            return None

        # special-case expand @id and @type (skips '@id' expansion)
        expanded_property = self._expand_iri(
            active_ctx, active_property, vocab=True)
        if expanded_property == '@id':
            return self._expand_iri(active_ctx, value, base=True)
        elif expanded_property == '@type':
            return self._expand_iri(active_ctx, value, vocab=True, base=True)

        # get type definition from context
        type_ = JsonLdProcessor.get_context_value(
            active_ctx, active_property, '@type')

        # do @id expansion (automatic for @graph)
        if (type_ == '@id' or (expanded_property == '@graph'
                               and _is_string(value))):
            return {'@id': self._expand_iri(active_ctx, value, base=True)}
        # do @id expansion w/vocab
        if type_ == '@vocab':
            return {'@id': self._expand_iri(
                active_ctx, value, vocab=True, base=True)}

        # do not expand keyword values
        if _is_keyword(expanded_property):
            return value

        rval = {}

        # other type
        if type_ is not None:
            rval['@type'] = type_
        # check for language tagging
        elif _is_string(value):
            language = JsonLdProcessor.get_context_value(
                active_ctx, active_property, '@language')
            if language is not None:
                rval['@language'] = language
        rval['@value'] = value

        return rval

    def _graph_to_rdf(self, graph, namer, options):
        """
        Creates an array of RDF triples for the given graph.

        :param graph: the graph to create RDF triples for.
        :param namer: the UniqueNamer for assigning blank node names.
        :param options: the RDF serialization options.

        :return: the array of RDF triples for the given graph.
        """
        rval = []
        for id_, node in sorted(graph.items()):
            for property, items in sorted(node.items()):
                if property == '@type':
                    property = RDF_TYPE
                elif _is_keyword(property):
                    continue

                for item in items:
                    # skip relative IRI subjects and predicates
                    if not (_is_absolute_iri(id_) and
                            _is_absolute_iri(property)):
                        continue

                    # RDF subject
                    subject = {}
                    if id_.startswith('_:'):
                        subject['type'] = 'blank node'
                    else:
                        subject['type'] = 'IRI'
                    subject['value'] = id_

                    # RDF predicate
                    predicate = {}
                    if property.startswith('_:'):
                        # skip bnode predicates unless producing
                        # generalized RDF
                        if not options['produceGeneralizedRdf']:
                            continue
                        predicate['type'] = 'blank node'
                    else:
                        predicate['type'] = 'IRI'
                    predicate['value'] = property

                    # convert @list to triples
                    if _is_list(item):
                        self._list_to_rdf(
                            item['@list'], namer, subject, predicate, rval)
                    # convert value or node object to triple
                    else:
                        object = self._object_to_rdf(item)
                        # skip None objects (they are relative IRIs)
                        if object is not None:
                            rval.append({
                                'subject': subject,
                                'predicate': predicate,
                                'object': object
                            })
        return rval

    def _list_to_rdf(self, list, namer, subject, predicate, triples):
        """
        Converts a @list value into a linked list of blank node RDF triples
        (and RDF collection).

        :param list: the @list value.
        :param namer: the UniqueNamer for assigning blank node names.
        :param subject: the subject for the head of the list.
        :param predicate: the predicate for the head of the list.
        :param triples: the array of triples to append to.
        """
        first = {'type': 'IRI', 'value': RDF_FIRST}
        rest = {'type': 'IRI', 'value': RDF_REST}
        nil = {'type': 'IRI', 'value': RDF_NIL}

        for item in list:
            blank_node = {'type': 'blank node', 'value': namer.get_name()}
            triples.append({
                'subject': subject,
                'predicate': predicate,
                'object': blank_node
            })

            subject = blank_node
            predicate = first
            object = self._object_to_rdf(item)
            # skip None objects (they are relative IRIs)
            if object is not None:
                triples.append({
                    'subject': subject,
                    'predicate': predicate,
                    'object': object
                })

            predicate = rest

        triples.append({
            'subject': subject,
            'predicate': predicate,
            'object': nil
        })

    def _object_to_rdf(self, item):
        """
        Converts a JSON-LD value object to an RDF literal or a JSON-LD string
        or node object to an RDF resource.

        :param item: the JSON-LD value or node object.

        :return: the RDF literal or RDF resource.
        """
        object = {}

        if _is_value(item):
            object['type'] = 'literal'
            value = item['@value']
            datatype = item.get('@type')

            # convert to XSD datatypes as appropriate
            if _is_bool(value):
                object['value'] = 'true' if value else 'false'
                object['datatype'] = datatype or XSD_BOOLEAN
            elif _is_double(value) or datatype == XSD_DOUBLE:
                # canonical double representation
                object['value'] = re.sub(r'(\d)0*E\+?0*(\d)', r'\1E\2',
                                         ('%1.15E' % value))
                object['datatype'] = datatype or XSD_DOUBLE
            elif _is_integer(value):
                object['value'] = str(value)
                object['datatype'] = datatype or XSD_INTEGER
            elif '@language' in item:
                object['value'] = value
                object['datatype'] = datatype or RDF_LANGSTRING
                object['language'] = item['@language']
            else:
                object['value'] = value
                object['datatype'] = datatype or XSD_STRING
        # convert string/node object to RDF
        else:
            id_ = item['@id'] if _is_object(item) else item
            if id_.startswith('_:'):
                object['type'] = 'blank node'
            else:
                object['type'] = 'IRI'
            object['value'] = id_

        # skip relative IRIs
        if object['type'] == 'IRI' and not _is_absolute_iri(object['value']):
            return None

        return object

    def _rdf_to_object(self, o, use_native_types):
        """
        Converts an RDF triple object to a JSON-LD object.

        :param o: the RDF triple object to convert.
        :param use_native_types: True to output native types, False not to.

        :return: the JSON-LD object.
        """
        # convert IRI/BlankNode object to JSON-LD
        if o['type'] == 'IRI' or o['type'] == 'blank node':
            return {'@id': o['value']}

        # convert literal object to JSON-LD
        rval = {'@value': o['value']}

        # add language
        if 'language' in o:
            rval['@language'] = o['language']
        # add datatype
        else:
            type_ = o['datatype']
            # use native types for certain xsd types
            if use_native_types:
                if type_ == XSD_BOOLEAN:
                    if rval['@value'] == 'true':
                        rval['@value'] = True
                    elif rval['@value'] == 'false':
                        rval['@value'] = False
                elif _is_numeric(rval['@value']):
                    if type_ == XSD_INTEGER:
                        if rval['@value'].isdigit():
                            rval['@value'] = int(rval['@value'])
                    elif type_ == XSD_DOUBLE:
                        rval['@value'] = float(rval['@value'])
                # do not add native type
                if type_ not in [XSD_BOOLEAN, XSD_INTEGER, XSD_DOUBLE,
                                 XSD_STRING]:
                    rval['@type'] = type_
            elif type_ != XSD_STRING:
                rval['@type'] = type_
        return rval

    def _create_node_map(
            self, input_, graphs, graph, namer, name=None, list_=None):
        """
        Recursively flattens the subjects in the given JSON-LD expanded
        input into a node map.

        :param input_: the JSON-LD expanded input.
        :param graphs: a map of graph name to subject map.
        :param graph: the name of the current graph.
        :param namer: the UniqueNamer for assigning blank node names.
        :param name: the name assigned to the current input if it is a bnode.
        :param list_: the list to append to, None for none.
        """
        # recurse through array
        if _is_array(input_):
            for e in input_:
                self._create_node_map(e, graphs, graph, namer, None, list_)
            return

        # add non-object to list
        if not _is_object(input_):
            if list_ is not None:
                list_.append(input_)
            return

        # add values to list
        if _is_value(input_):
            if '@type' in input_:
                type_ = input_['@type']
                # rename @type blank node
                if type_.startswith('_:'):
                    type_ = input_['@type'] = namer.get_name(type_)
            if list_ is not None:
                list_.append(input_)
            return

        # Note: At this point, input must be a subject.

        # spec requires @type to be named first, so assign names early
        if '@type' in input_:
            for type_ in input_['@type']:
                if type_.startswith('_:'):
                    namer.get_name(type_)

        # get name for subject
        if name is None:
            name = input_.get('@id')
            if _is_bnode(input_):
                name = namer.get_name(name)

        # add subject reference to list
        if list_ is not None:
            list_.append({'@id': name})

        # create new subject or merge into existing one
        subject = graphs.setdefault(graph, {}).setdefault(name, {'@id': name})
        for property, objects in sorted(input_.items()):
            # skip @id
            if property == '@id':
                continue

            # handle reverse properties
            if property == '@reverse':
                referenced_node = {'@id': name}
                reverse_map = input_['@reverse']
                for reverse_property, items in reverse_map.items():
                    for item in items:
                        item_name = item.get('@id')
                        if _is_bnode(item):
                            item_name = namer.get_name(item_name)
                        self._create_node_map(
                            item, graphs, graph, namer, item_name)
                        JsonLdProcessor.add_value(
                            graphs[graph][item_name], reverse_property,
                            referenced_node,
                            {'propertyIsArray': True, 'allowDuplicate': False})
                continue

            # recurse into graph
            if property == '@graph':
                # add graph subjects map entry
                graphs.setdefault(name, {})
                g = graph if graph == '@merged' else name
                self._create_node_map(objects, graphs, g, namer)
                continue

            # copy non-@type keywords
            if property != '@type' and _is_keyword(property):
                if property == '@index' and '@index' in subject \
                    and (input_['@index'] != subject['@index'] or
                         input_['@index']['@id'] != subject['@index']['@id']):
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; conflicting @index property '
                        ' detected.', 'jsonld.SyntaxError',
                        {'subject': subject}, code='conflicting indexes')
                subject[property] = input_[property]
                continue

            # if property is a bnode, assign it a new id
            if property.startswith('_:'):
                property = namer.get_name(property)

            # ensure property is added for empty arrays
            if len(objects) == 0:
                JsonLdProcessor.add_value(
                    subject, property, [], {'propertyIsArray': True})
                continue

            for o in objects:
                if property == '@type':
                    # rename @type blank nodes
                    o = namer.get_name(o) if o.startswith('_:') else o

                # handle embedded subject or subject reference
                if _is_subject(o) or _is_subject_reference(o):
                    # rename blank node @id
                    id_ = o.get('@id')
                    if _is_bnode(o):
                        id_ = namer.get_name(id_)

                    # add reference and recurse
                    JsonLdProcessor.add_value(
                        subject, property, {'@id': id_},
                        {'propertyIsArray': True, 'allowDuplicate': False})
                    self._create_node_map(o, graphs, graph, namer, id_)
                # handle @list
                elif _is_list(o):
                    olist = []
                    self._create_node_map(
                        o['@list'], graphs, graph, namer, name, olist)
                    o = {'@list': olist}
                    JsonLdProcessor.add_value(
                        subject, property, o,
                        {'propertyIsArray': True, 'allowDuplicate': False})
                # handle @value
                else:
                    self._create_node_map(o, graphs, graph, namer, name)
                    JsonLdProcessor.add_value(
                        subject, property, o,
                        {'propertyIsArray': True, 'allowDuplicate': False})

    def _match_frame(self, state, subjects, frame, parent, property):
        """
        Frames subjects according to the given frame.

        :param state: the current framing state.
        :param subjects: the subjects to filter.
        :param frame: the frame.
        :param parent: the parent subject or top-level array.
        :param property: the parent property, initialized to None.
        """
        # validate the frame
        self._validate_frame(frame)
        frame = frame[0]

        # get flags for current frame
        options = state['options']
        flags = {
            'embed': self._get_frame_flag(frame, options, 'embed'),
            'explicit': self._get_frame_flag(frame, options, 'explicit'),
            'requireAll': self._get_frame_flag(frame, options, 'requireAll')
        }

        # filter out subjects that match the frame
        matches = self._filter_subjects(state, subjects, frame, flags)

        # add matches to output
        for id_, subject in sorted(matches.items()):
            if flags['embed'] == '@link' and id_ in state['link']:
                # TODO: may want to also match an existing linked subject
                # against the current frame ... so different frames could
                # produce different subjects that are only shared in-memory
                # when the frames are the same

                # add existing linked subject
                self._add_frame_output(parent, property, state['link'][id_])
                continue

            # Note: In order to treat each top-level match as a
            # compartmentalized result, clear the unique embedded subjects map
            # when the property is None, which only occurs at the top-level.
            if property is None:
                state['uniqueEmbeds'] = {}

            # start output for subject
            output = {'@id': id_}
            state['link'][id_] = output

            # if embed is @never or if a circular reference would be created
            # by an embed, the subject cannot be embedded, just add the
            # reference; note that a circular reference won't occur when the
            # embed flag is `@link` as the above check will short-circuit
            # before reaching this point
            if (flags['embed'] == '@never' or self._creates_circular_reference(
                    subject, state['subjectStack'])):
                self._add_frame_output(parent, property, output)
                continue

            # if only the last match should be embedded
            if flags['embed'] == '@last':
                # remove any existing embed
                if id_ in state['uniqueEmbeds']:
                    self._remove_embed(state, id_)
                state['uniqueEmbeds'][id_] = {
                    'parent': parent,
                    'property': property
                }

            # push matching subject onto stack to enable circular embed checks
            state['subjectStack'].append(subject)

            # iterate over subject properties in order
            for prop, objects in sorted(subject.items()):
                # copy keywords to output
                if _is_keyword(prop):
                    output[prop] = copy.deepcopy(subject[prop])
                    continue

                # explicit is on and property isn't in frame, skip processing
                if flags['explicit'] and prop not in frame:
                    continue

                # add objects
                objects = subject[prop]
                for o in objects:
                    # recurse into list
                    if _is_list(o):
                        # add empty list
                        list_ = {'@list': []}
                        self._add_frame_output(output, prop, list_)

                        # add list objects
                        src = o['@list']
                        for o in src:
                            if _is_subject_reference(o):
                                # recurse into subject reference
                                if prop in frame:
                                    subframe = frame[prop][0]['@list']
                                else:
                                    subframe = self._create_implicit_frame(
                                        flags)
                                self._match_frame(
                                    state, [o['@id']],
                                    subframe, list_, '@list')
                            else:
                                # include other values automatically
                                self._add_frame_output(
                                    list_, '@list', copy.deepcopy(o))
                        continue

                    if _is_subject_reference(o):
                        # recurse into subject reference
                        if prop in frame:
                            subframe = frame[prop]
                        else:
                            subframe = self._create_implicit_frame(flags)
                        self._match_frame(
                            state, [o['@id']], subframe, output, prop)
                    else:
                        # include other values automatically
                        self._add_frame_output(output, prop, copy.deepcopy(o))

            # handle defaults in order
            for prop in sorted(frame.keys()):
                # skip keywords
                if _is_keyword(prop):
                    continue
                # if omit default is off, then include default values for
                # properties that appear in the next frame but are not in
                # the matching subject
                next = frame[prop][0]
                omit_default_on = self._get_frame_flag(
                    next, options, 'omitDefault')
                if not omit_default_on and prop not in output:
                    preserve = '@null'
                    if '@default' in next:
                        preserve = copy.deepcopy(next['@default'])
                    preserve = JsonLdProcessor.arrayify(preserve)
                    output[prop] = [{'@preserve': preserve}]

            # add output to parent
            self._add_frame_output(parent, property, output)

            # pop matching subject from circular ref-checking stack
            state['subjectStack'].pop()

    def _create_implicit_frame(self, flags):
        """
        Creates an implicit frame when recursing through subject matches. If
        a frame doesn't have an explicit frame for a particular property, then
        a wildcard child frame will be created that uses the same flags that
        the parent frame used.

        :param flags: the current framing flags.

        :return: the implicit frame.
        """
        frame = {}
        for key in flags:
            frame['@' + key] = [flags[key]]
        return [frame]

    def _creates_circular_reference(self, subject_to_embed, subject_stack):
        """
        Checks the current subject stack to see if embedding the given subject
        would cause a circular reference.

        :param subject_to_embed: the subject to embed.
        :param subject_stack: the current stack of subjects.

        :return: true if a circular reference would be created, false if not.
        """
        for subject in reversed(subject_stack[:-1]):
            if subject['@id'] == subject_to_embed['@id']:
                return True
        return False

    def _get_frame_flag(self, frame, options, name):
        """
        Gets the frame flag value for the given flag name.

        :param frame: the frame.
        :param options: the framing options.
        :param name: the flag name.

        :return: the flag value.
        """
        rval = frame.get('@' + name, [options[name]])[0]
        if name == 'embed':
            # default is "@last"
            # backwards-compatibility support for "embed" maps:
            # true => "@last"
            # false => "@never"
            if rval is True:
                rval = '@last'
            elif rval is False:
                rval = '@never'
            elif rval != '@always' and rval != '@never' and rval != '@link':
                rval = '@last'
        return rval

    def _validate_frame(self, frame):
        """
        Validates a JSON-LD frame, throwing an exception if the frame is
        invalid.

        :param frame: the frame to validate.
        """
        if (not _is_array(frame) or len(frame) != 1 or
                not _is_object(frame[0])):
            raise JsonLdError(
                'Invalid JSON-LD syntax; a JSON-LD frame must be a single '
                'object.', 'jsonld.SyntaxError', {'frame': frame})

    def _filter_subjects(self, state, subjects, frame, flags):
        """
        Returns a map of all of the subjects that match a parsed frame.

        :param state: the current framing state.
        :param subjects: the set of subjects to filter.
        :param frame: the parsed frame.
        :param flags: the frame flags.

        :return: all of the matched subjects.
        """
        rval = {}
        for id_ in subjects:
            subject = state['subjects'][id_]
            if self._filter_subject(subject, frame, flags):
                rval[id_] = subject
        return rval

    def _filter_subject(self, subject, frame, flags):
        """
        Returns True if the given subject matches the given frame.

        :param subject: the subject to check.
        :param frame: the frame to check.
        :param flags: the frame flags.

        :return: True if the subject matches, False if not.
        """
        # check @type (object value means 'any' type, fall through to
        # ducktyping)
        if ('@type' in frame and
                not (len(frame['@type']) == 1 and
                     _is_object(frame['@type'][0]))):
            types = frame['@type']
            for t in types:
                # any matching @type is a match
                if JsonLdProcessor.has_value(subject, '@type', t):
                    return True
            return False

        # check ducktype
        wildcard = True
        matches_some = False
        for k, v in frame.items():
            if _is_keyword(k):
                # skip non-@id and non-@type
                if k != '@id' and k != '@type':
                    continue
                wildcard = True

                # check @id for a specific @id value
                if k == '@id' and _is_string(v):
                    if subject.get(k) != v:
                        return False

                matches_some = True
                continue

            wildcard = False

            if k in subject:
                # v == [] means do not match if property is present
                if _is_array(v) and len(v) == 0:
                    return False

                matches_some = True
                continue

            # all properties must match to be a duck unless a @default is
            # specified
            has_default = (_is_array(v) and len(v) == 1 and
                           _is_object(v[0]) and '@default' in v[0])
            if flags['requireAll'] and not has_default:
                return False

        # return true if wildcard or subject matches some properties
        return wildcard or matches_some

    def _remove_embed(self, state, id_):
        """
        Removes an existing embed.

        :param state: the current framing state.
        :param id_: the @id of the embed to remove.
        """
        # get existing embed
        embeds = state['uniqueEmbeds']
        embed = embeds[id_]
        property = embed['property']

        # create reference to replace embed
        subject = {'@id': id_}

        # remove existing embed
        if _is_array(embed['parent']):
            # replace subject with reference
            for i, parent in enumerate(embed['parent']):
                if JsonLdProcessor.compare_values(parent, subject):
                    embed['parent'][i] = subject
                    break
        else:
            # replace subject with reference
            use_array = _is_array(embed['parent'][property])
            JsonLdProcessor.remove_value(
                embed['parent'], property, subject,
                {'propertyIsArray': use_array})
            JsonLdProcessor.add_value(
                embed['parent'], property, subject,
                {'propertyIsArray': use_array})

        # recursively remove dependent dangling embeds
        def remove_dependents(id_):
            # get embed keys as a separate array to enable deleting keys
            # in map
            try:
                ids = list(embeds.iterkeys())
            except AttributeError:
                ids = list(embeds.keys())
            for next in ids:
                if (next in embeds and
                        _is_object(embeds[next]['parent']) and
                        embeds[next]['parent']['@id'] == id_):
                    del embeds[next]
                    remove_dependents(next)
        remove_dependents(id_)

    def _add_frame_output(self, parent, property, output):
        """
        Adds framing output to the given parent.

        :param parent: the parent to add to.
        :param property: the parent property.
        :param output: the output to add.
        """
        if _is_object(parent):
            JsonLdProcessor.add_value(
                parent, property, output, {'propertyIsArray': True})
        else:
            parent.append(output)

    def _remove_preserve(self, ctx, input_, options):
        """
        Removes the @preserve keywords as the last step of the framing
        algorithm.

        :param ctx: the active context used to compact the input.
        :param input_: the framed, compacted output.
        :param options: the compaction options used.

        :return: the resulting output.
        """
        # recurse through arrays
        if _is_array(input_):
            output = []
            for e in input_:
                result = self._remove_preserve(ctx, e, options)
                # drop Nones from arrays
                if result is not None:
                    output.append(result)
            return output
        elif _is_object(input_):
            # remove @preserve
            if '@preserve' in input_:
                if input_['@preserve'] == '@null':
                    return None
                return input_['@preserve']

            # skip @values
            if _is_value(input_):
                return input_

            # recurse through @lists
            if _is_list(input_):
                input_['@list'] = self._remove_preserve(
                    ctx, input_['@list'], options)
                return input_

            # handle in-memory linked nodes
            id_alias = self._compact_iri(ctx, '@id')
            if id_alias in input_:
                id_ = input_[id_alias]
                if id_ in options['link']:
                    try:
                        idx = options['link'][id_].index(input_)
                        # already visited
                        return options['link'][id_][idx]
                    except BaseException:
                        # prevent circular visitation
                        options['link'][id_].append(input_)
                else:
                    # prevent circular visitation
                    options['link'][id_] = [input_]

            # recurse through properties
            for prop, v in input_.items():
                result = self._remove_preserve(ctx, v, options)
                container = JsonLdProcessor.get_context_value(
                    ctx, prop, '@container')
                if (options['compactArrays'] and
                        _is_array(result) and len(result) == 1 and
                        container != '@set' and container != '@list'):
                    result = result[0]
                input_[prop] = result
        return input_

    def _hash_quads(self, id_, bnodes):
        """
        Hashes all of the quads about a blank node.

        :param id_: the ID of the bnode to hash quads for.
        :param bnodes: the mapping of bnodes to quads.
        :param namer: the canonical bnode namer.

        :return: the new hash.
        """
        # return cached hash
        if 'hash' in bnodes[id_]:
            return bnodes[id_]['hash']

        # serialize all of bnode's quads
        quads = bnodes[id_]['quads']
        nquads = []
        for quad in quads:
            nquads.append(JsonLdProcessor.to_nquad(
                quad, quad['name']['value'] if 'name' in quad else None, id_))
        # sort serialized quads
        nquads.sort()
        # cache and return hashed quads
        md = hashlib.sha1()
        md.update(''.join(nquads).encode('utf-8'))
        hash = bnodes[id_]['hash'] = md.hexdigest()
        return hash

    def _hash_paths(self, id_, bnodes, namer, path_namer):
        """
        Produces a hash for the paths of adjacent bnodes for a bnode,
        incorporating all information about its subgraph of bnodes. This
        method will recursively pick adjacent bnode permutations that produce
        the lexicographically-least 'path' serializations.

        :param id_: the ID of the bnode to hash paths for.
        :param bnodes: the map of bnode quads.
        :param namer: the canonical bnode namer.
        :param path_namer: the namer used to assign names to adjacent bnodes.

        :return: the hash and path namer used.
        """
        # create SHA-1 digest
        md = hashlib.sha1()

        # group adjacent bnodes by hash, keep properties & references separate
        groups = {}
        quads = bnodes[id_]['quads']
        for quad in quads:
            # get adjacent bnode
            bnode = self._get_adjacent_bnode_name(quad['subject'], id_)
            if bnode is not None:
                # normal property
                direction = 'p'
            else:
                bnode = self._get_adjacent_bnode_name(quad['object'], id_)
                if bnode is None:
                    continue
                # reference property
                direction = 'r'

            # get bnode name (try canonical, path, then hash)
            if namer.is_named(bnode):
                name = namer.get_name(bnode)
            elif path_namer.is_named(bnode):
                name = path_namer.get_name(bnode)
            else:
                name = self._hash_quads(bnode, bnodes)

            # hash direction, property, and bnode name/hash
            group_md = hashlib.sha1()
            group_md.update(direction.encode('utf-8'))
            group_md.update(quad['predicate']['value'].encode('utf-8'))
            group_md.update(name.encode('utf-8'))
            group_hash = group_md.hexdigest()

            # add bnode to hash group
            groups.setdefault(group_hash, []).append(bnode)

        # iterate over groups in sorted hash order
        for group_hash, group in sorted(groups.items()):
            # digest group hash
            md.update(group_hash.encode('utf8'))

            # choose a path and namer from the permutations
            chosen_path = None
            chosen_namer = None
            for permutation in permutations(group):
                path_namer_copy = copy.deepcopy(path_namer)

                # build adjacent path
                path = ''
                skipped = False
                recurse = []
                for bnode in permutation:
                    # use canonical name if available
                    if namer.is_named(bnode):
                        path += namer.get_name(bnode)
                    else:
                        # recurse if bnode isn't named in the path yet
                        if not path_namer_copy.is_named(bnode):
                            recurse.append(bnode)
                        path += path_namer_copy.get_name(bnode)

                    # skip permutation if path is already >= chosen path
                    if (chosen_path is not None and
                            len(path) >= len(chosen_path) and
                            path > chosen_path):
                        skipped = True
                        break

                # recurse
                if not skipped:
                    for bnode in recurse:
                        result = self._hash_paths(
                            bnode, bnodes, namer, path_namer_copy)
                        path += path_namer_copy.get_name(bnode)
                        path += '<%s>' % result['hash']
                        path_namer_copy = result['pathNamer']

                        # skip permutation if path is already >= chosen path
                        if (chosen_path is not None and
                                len(path) >= len(chosen_path) and
                                path > chosen_path):
                            skipped = True
                            break

                if (not skipped and
                        (chosen_path is None or path < chosen_path)):
                    chosen_path = path
                    chosen_namer = path_namer_copy

            # digest chosen path and update namer
            md.update(chosen_path.encode('utf-8'))
            path_namer = chosen_namer

        # return SHA-1 hash and path namer
        return {'hash': md.hexdigest(), 'pathNamer': path_namer}

    def _get_adjacent_bnode_name(self, node, id_):
        """
        A helper function that gets the blank node name from an RDF quad
        node (subject or object). If the node is not a blank node or its
        value does not match the given blank node ID, it will be returned.

        :param node: the RDF quad node.
        :param id_: the ID of the blank node to look next to.

        :return: the adjacent blank node name or None if none was found.
        """
        if node['type'] == 'blank node' and node['value'] != id_:
            return node['value']
        return None

    def _select_term(
            self, active_ctx, iri, value, containers,
            type_or_language, type_or_language_value):
        """
        Picks the preferred compaction term from the inverse context entry.

        :param active_ctx: the active context.
        :param iri: the IRI to pick the term for.
        :param value: the value to pick the term for.
        :param containers: the preferred containers.
        :param type_or_language: either '@type' or '@language'.
        :param type_or_language_value: the preferred value for '@type' or
          '@language'

        :return: the preferred term.
        """
        if type_or_language_value is None:
            type_or_language_value = '@null'

        # preferred options for the value of @type or language
        prefs = []

        # determine prefs for @id based on whether value compacts to term
        if ((type_or_language_value == '@id' or
                type_or_language_value == '@reverse') and
                _is_subject_reference(value)):
            # prefer @reverse first
            if type_or_language_value == '@reverse':
                prefs.append('@reverse')
            # try to compact value to a term
            term = self._compact_iri(
                active_ctx, value['@id'], None, vocab=True)
            mapping = active_ctx['mappings'].get(term)
            if term is not None and mapping and mapping['@id'] == value['@id']:
                # prefer @vocab
                prefs.extend(['@vocab', '@id'])
            else:
                # prefer @id
                prefs.extend(['@id', '@vocab'])
        else:
            prefs.append(type_or_language_value)
        prefs.append('@none')

        container_map = active_ctx['inverse'][iri]
        for container in containers:
            # skip container if not in map
            if container not in container_map:
                continue
            type_or_language_value_map = (
                container_map[container][type_or_language])
            for pref in prefs:
                # skip type/language preference if not in map
                if pref not in type_or_language_value_map:
                    continue
                return type_or_language_value_map[pref]
        return None

    def _compact_iri(
            self, active_ctx, iri, value=None, vocab=False, reverse=False):
        """
        Compacts an IRI or keyword into a term or CURIE if it can be. If the
        IRI has an associated value it may be passed.

        :param active_ctx: the active context to use.
        :param iri: the IRI to compact.
        :param value: the value to check or None.
        :param vocab: True to compact using @vocab if available, False not to.
        :param reverse: True if a reverse property is being compacted, False if
          not.

        :return: the compacted term, prefix, keyword alias, or original IRI.
        """
        # can't compact None
        if iri is None:
            return iri

        # term is a keyword, force vocab to True
        if _is_keyword(iri):
            vocab = True

        # use inverse context to pick a term if iri is relative to vocab
        if vocab and iri in self._get_inverse_context(active_ctx):
            default_language = active_ctx.get('@language', '@none')

            # prefer @index if available in value
            containers = []
            if _is_object(value) and '@index' in value:
                containers.append('@index')

            # defaults for term selection based on type/language
            type_or_language = '@language'
            type_or_language_value = '@null'

            if reverse:
                type_or_language = '@type'
                type_or_language_value = '@reverse'
                containers.append('@set')
            # choose most specific term that works for all elements in @list
            elif _is_list(value):
                # only select @list containers if @index is NOT in value
                if '@index' not in value:
                    containers.append('@list')
                list_ = value['@list']
                common_language = default_language if len(list_) == 0 else None
                common_type = None
                for item in list_:
                    item_language = '@none'
                    item_type = '@none'
                    if _is_value(item):
                        if '@language' in item:
                            item_language = item['@language']
                        elif '@type' in item:
                            item_type = item['@type']
                        # plain literal
                        else:
                            item_language = '@null'
                    else:
                        item_type = '@id'
                    if common_language is None:
                        common_language = item_language
                    elif item_language != common_language and _is_value(item):
                        common_language = '@none'
                    if common_type is None:
                        common_type = item_type
                    elif item_type != common_type:
                        common_type = '@none'
                    # there are different languages and types in the list, so
                    # choose the most generic term, no need to keep iterating
                    if common_language == '@none' and common_type == '@none':
                        break
                if common_language is None:
                    common_language = '@none'
                if common_type is None:
                    common_type = '@none'
                if common_type != '@none':
                    type_or_language = '@type'
                    type_or_language_value = common_type
                else:
                    type_or_language_value = common_language
            # non-@list
            else:
                if _is_value(value):
                    if '@language' in value and '@index' not in value:
                        containers.append('@language')
                        type_or_language_value = value['@language']
                    elif '@type' in value:
                        type_or_language = '@type'
                        type_or_language_value = value['@type']
                else:
                    type_or_language = '@type'
                    type_or_language_value = '@id'
                containers.append('@set')

            # do term selection
            containers.append('@none')
            term = self._select_term(
                active_ctx, iri, value, containers,
                type_or_language, type_or_language_value)
            if term is not None:
                return term

        # no term match, use @vocab if available
        if vocab:
            if '@vocab' in active_ctx:
                vocab_ = active_ctx['@vocab']
                if iri.startswith(vocab_) and iri != vocab_:
                    # use suffix as relative iri if it is not a term in the
                    # active context
                    suffix = iri[len(vocab_):]
                    if suffix not in active_ctx['mappings']:
                        return suffix

        # no term or @vocab match, check for possible CURIEs
        candidate = None
        for term, definition in active_ctx['mappings'].items():
            # skip terms with colons, they can't be prefixes
            if ':' in term:
                continue
            # skip entries with @ids that are not partial matches
            if (definition is None or definition['@id'] == iri or
                    not iri.startswith(definition['@id'])):
                continue

            # a CURIE is usable if:
            # 1. it has no mapping, OR
            # 2. value is None, which means we're not compacting an @value, AND
            #  the mapping matches the IRI
            curie = term + ':' + iri[len(definition['@id']):]
            is_usable_curie = (
                curie not in active_ctx['mappings'] or
                (value is None and
                 active_ctx['mappings'].get(curie, {}).get('@id') == iri))

            # select curie if it is shorter or the same length but
            # lexicographically less than the current choice
            if (is_usable_curie and (candidate is None or
                                     _compare_shortest_least(curie,
                                                             candidate) < 0)):
                candidate = curie

        # return curie candidate
        if candidate is not None:
            return candidate

        # compact IRI relative to base
        if not vocab:
            return remove_base(active_ctx['@base'], iri)

        # return IRI as is
        return iri

    def _compact_value(self, active_ctx, active_property, value):
        """
        Performs value compaction on an object with @value or @id as the only
        property.

        :param active_ctx: the active context.
        :param active_property: the active property that points to the value.
        :param value: the value to compact.
        """
        if _is_value(value):
            # get context rules
            type_ = JsonLdProcessor.get_context_value(
                active_ctx, active_property, '@type')
            language = JsonLdProcessor.get_context_value(
                active_ctx, active_property, '@language')
            container = JsonLdProcessor.get_context_value(
                active_ctx, active_property, '@container')

            # whether or not the value has an @index that must be preserved
            preserve_index = '@index' in value and container != '@index'

            # if there's no @index to preserve
            if not preserve_index:
                # matching @type or @language specified in context, compact
                if (('@type' in value and value['@type'] == type_) or
                    ('@language' in value and
                     value['@language'] == language)):
                    return value['@value']

            # return just the value of @value if all are true:
            # 1. @value is the only key or @index isn't being preserved
            # 2. there is no default language or @value is not a string or
            #  the key has a mapping with a null @language
            key_count = len(value)
            is_value_only_key = \
                (key_count == 1 or (key_count == 2 and
                                    '@index' in value and not preserve_index))
            has_default_language = '@language' in active_ctx
            is_value_string = _is_string(value['@value'])
            has_null_mapping = (
                active_ctx['mappings'].get(active_property) is not None and
                '@language' in active_ctx['mappings'][active_property] and
                active_ctx['mappings'][active_property]['@language'] is None)
            if (is_value_only_key and (
                    not has_default_language or not is_value_string or
                    has_null_mapping)):
                return value['@value']

            rval = {}

            # preserve @index
            if preserve_index:
                rval[self._compact_iri(active_ctx, '@index')] = value['@index']

            # compact @type IRI
            if '@type' in value:
                rval[self._compact_iri(active_ctx, '@type')] = (
                    self._compact_iri(active_ctx, value['@type'], vocab=True))
            # alias @language
            elif '@language' in value:
                rval[self._compact_iri(active_ctx, '@language')] = (
                    value['@language'])

            # alias @value
            rval[self._compact_iri(active_ctx, '@value')] = value['@value']

            return rval

        # value is a subject reference
        expanded_property = self._expand_iri(
            active_ctx, active_property, vocab=True)
        type_ = JsonLdProcessor.get_context_value(
            active_ctx, active_property, '@type')
        compacted = self._compact_iri(
            active_ctx, value['@id'], vocab=(type_ == '@vocab'))

        # compact to scalar
        if type_ in ['@id', '@vocab'] or expanded_property == '@graph':
            return compacted

        rval = {}
        rval[self._compact_iri(active_ctx, '@id')] = compacted
        return rval

    def _create_term_definition(self, active_ctx, local_ctx, term, defined):
        """
        Creates a term definition during context processing.

        :param active_ctx: the current active context.
        :param local_ctx: the local context being processed.
        :param term: the key in the local context to define the mapping for.
        :param defined: a map of defining/defined keys to detect cycles
          and prevent double definitions.
        """
        if term in defined:
            # term already defined
            if defined[term]:
                return
            # cycle detected
            raise JsonLdError(
                'Cyclical context definition detected.',
                'jsonld.CyclicalContext', {
                    'context': local_ctx,
                    'term': term
                }, code='cyclic IRI mapping')

        # now defining term
        defined[term] = False

        if _is_keyword(term):
            raise JsonLdError(
                'Invalid JSON-LD syntax; keywords cannot be overridden.',
                'jsonld.SyntaxError', {'context': local_ctx, 'term': term},
                code='keyword redefinition')

        if term == '':
            raise JsonLdError(
                'Invalid JSON-LD syntax; a term cannot be an empty string.',
                'jsonld.SyntaxError', {'context': local_ctx},
                code='invalid term definition')

        # remove old mapping
        if term in active_ctx['mappings']:
            del active_ctx['mappings'][term]

        # get context term value
        value = local_ctx[term]

        # clear context entry
        if (value is None or (_is_object(value) and '@id' in value and
                              value['@id'] is None)):
            active_ctx['mappings'][term] = None
            defined[term] = True
            return

        # convert short-hand value to object w/@id
        if _is_string(value):
            value = {'@id': value}

        if not _is_object(value):
            raise JsonLdError(
                'Invalid JSON-LD syntax; @context property values must be '
                'strings or objects.', 'jsonld.SyntaxError',
                {'context': local_ctx}, code='invalid term definition')

        # create new mapping
        mapping = active_ctx['mappings'][term] = {'reverse': False}

        if '@reverse' in value:
            if '@id' in value:
                raise JsonLdError(
                    'Invalid JSON-LD syntax; an @reverse term definition must '
                    'not contain @id.', 'jsonld.SyntaxError',
                    {'context': local_ctx}, code='invalid reverse property')
            reverse = value['@reverse']
            if not _is_string(reverse):
                raise JsonLdError(
                    'Invalid JSON-LD syntax; @context @reverse value must be '
                    'a string.', 'jsonld.SyntaxError', {'context': local_ctx},
                    code='invalid IRI mapping')

            # expand and add @id mapping
            id_ = self._expand_iri(
                active_ctx, reverse, vocab=True, base=False,
                local_ctx=local_ctx, defined=defined)
            if not _is_absolute_iri(id_):
                raise JsonLdError(
                    'Invalid JSON-LD syntax; @context @reverse value must be '
                    'an absolute IRI or a blank node identifier.',
                    'jsonld.SyntaxError', {'context': local_ctx},
                    code='invalid IRI mapping')
            mapping['@id'] = id_
            mapping['reverse'] = True
        elif '@id' in value:
            id_ = value['@id']
            if not _is_string(id_):
                raise JsonLdError(
                    'Invalid JSON-LD syntax; @context @id value must be a '
                    'string.', 'jsonld.SyntaxError',
                    {'context': local_ctx}, code='invalid IRI mapping')
            if id_ != term:
                # add @id to mapping
                id_ = self._expand_iri(
                    active_ctx, id_, vocab=True, base=False,
                    local_ctx=local_ctx, defined=defined)
                if not _is_absolute_iri(id_) and not _is_keyword(id_):
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; @context @id value must be '
                        'an absolute IRI, a blank node identifier, or a '
                        'keyword.', 'jsonld.SyntaxError',
                        {'context': local_ctx}, code='invalid IRI mapping')
                mapping['@id'] = id_
        if '@id' not in mapping:
            # see if the term has a prefix
            colon = term.find(':')
            if colon != -1:
                prefix = term[0:colon]
                if prefix in local_ctx:
                    # define parent prefix
                    self._create_term_definition(
                        active_ctx, local_ctx, prefix, defined)

                # set @id based on prefix parent
                if active_ctx['mappings'].get(prefix) is not None:
                    suffix = term[colon + 1:]
                    mapping['@id'] = (active_ctx['mappings'][prefix]['@id'] +
                                      suffix)
                # term is an absolute IRI
                else:
                    mapping['@id'] = term
            else:
                # non-IRIs MUST define @ids if @vocab not available
                if '@vocab' not in active_ctx:
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; @context terms must define '
                        'an @id.', 'jsonld.SyntaxError', {
                            'context': local_ctx,
                            'term': term
                        }, code='invalid IRI mapping')
                # prepend vocab to term
                mapping['@id'] = active_ctx['@vocab'] + term

        # IRI mapping now defined
        defined[term] = True

        if '@type' in value:
            type_ = value['@type']
            if not _is_string(type_):
                raise JsonLdError(
                    'Invalid JSON-LD syntax; @context @type value must be '
                    'a string.', 'jsonld.SyntaxError',
                    {'context': local_ctx}, code='invalid type mapping')
            if type_ != '@id' and type_ != '@vocab':
                # expand @type to full IRI
                type_ = self._expand_iri(
                    active_ctx, type_, vocab=True,
                    local_ctx=local_ctx, defined=defined)
                if not _is_absolute_iri(type_):
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; an @context @type value must '
                        'be an absolute IRI.', 'jsonld.SyntaxError',
                        {'context': local_ctx}, code='invalid type mapping')
                if type_.startswith('_:'):
                    raise JsonLdError(
                        'Invalid JSON-LD syntax; an @context @type values '
                        'must be an IRI, not a blank node identifier.',
                        'jsonld.SyntaxError', {'context': local_ctx},
                        code='invalid type mapping')
            # add @type to mapping
            mapping['@type'] = type_

        if '@container' in value:
            container = value['@container']
            if container not in ['@list', '@set', '@index', '@language']:
                raise JsonLdError(
                    'Invalid JSON-LD syntax; @context @container value '
                    'must be one of the following: @list, @set, @index, or '
                    '@language.', 'jsonld.SyntaxError',
                    {'context': local_ctx}, code='invalid container mapping')
            if (mapping['reverse'] and container != '@index' and
                    container != '@set' and container is not None):
                raise JsonLdError(
                    'Invalid JSON-LD syntax; @context @container value for '
                    'an @reverse type definition must be @index or @set.',
                    'jsonld.SyntaxError', {'context': local_ctx},
                    code='invalid reverse property')

            # add @container to mapping
            mapping['@container'] = container

        if '@language' in value and '@type' not in value:
            language = value['@language']
            if not (language is None or _is_string(language)):
                raise JsonLdError(
                    'Invalid JSON-LD syntax; @context @language value must be '
                    'a string or null.', 'jsonld.SyntaxError',
                    {'context': local_ctx}, code='invalid language mapping')
            # add @language to mapping
            if language is not None:
                language = language.lower()
            mapping['@language'] = language

        # disallow aliasing @context and @preserve
        id_ = mapping['@id']
        if id_ == '@context' or id_ == '@preserve':
            raise JsonLdError(
                'Invalid JSON-LD syntax; @context and @preserve '
                'cannot be aliased.', 'jsonld.SyntaxError',
                {'context': local_ctx}, code='invalid keyword alias')

    def _expand_iri(
            self, active_ctx, value, base=False, vocab=False,
            local_ctx=None, defined=None):
        """
        Expands a string value to a full IRI. The string may be a term, a
        prefix, a relative IRI, or an absolute IRI. The associated absolute
        IRI will be returned.

        :param active_ctx: the current active context.
        :param value: the string value to expand.
        :param base: True to resolve IRIs against the base IRI, False not to.
        :param vocab: True to concatenate after @vocab, False not to.
        :param local_ctx: the local context being processed (only given if
          called during context processing).
        :param defined: a map for tracking cycles in context definitions (only
          given if called during context processing).

        :return: the expanded value.
        """
        # already expanded
        if value is None or _is_keyword(value):
            return value

        # define dependency not if defined
        if (local_ctx and value in local_ctx and
                defined.get(value) is not True):
            self._create_term_definition(active_ctx, local_ctx, value, defined)

        if vocab and value in active_ctx['mappings']:
            mapping = active_ctx['mappings'].get(value)
            # value is explicitly ignored with None mapping
            if mapping is None:
                return None
            # value is a term
            return mapping['@id']

        # split value into prefix:suffix
        if ':' in value:
            prefix, suffix = value.split(':', 1)

            # do not expand blank nodes (prefix of '_') or already-absolute
            # IRIs (suffix of '//')
            if prefix == '_' or suffix.startswith('//'):
                return value

            # prefix dependency not defined, define it
            if local_ctx and prefix in local_ctx:
                self._create_term_definition(
                    active_ctx, local_ctx, prefix, defined)

            # use mapping if prefix is defined
            mapping = active_ctx['mappings'].get(prefix)
            if mapping:
                return mapping['@id'] + suffix

            # already absolute IRI
            return value

        # prepend vocab
        if vocab and '@vocab' in active_ctx:
            return active_ctx['@vocab'] + value

        # resolve against base
        rval = value
        if base:
            rval = prepend_base(active_ctx['@base'], rval)

        return rval

    def _find_context_urls(self, input_, urls, replace, base):
        """
        Finds all @context URLs in the given JSON-LD input.

        :param input_: the JSON-LD input.
        :param urls: a map of URLs (url => False/@contexts).
        :param replace: True to replace the URLs in the given input with
                 the @contexts from the urls map, False not to.
        :param base: the base URL to resolve relative URLs against.
        """
        if _is_array(input_):
            for e in input_:
                self._find_context_urls(e, urls, replace, base)
        elif _is_object(input_):
            for k, v in input_.items():
                if k != '@context':
                    self._find_context_urls(v, urls, replace, base)
                    continue

                # array @context
                if _is_array(v):
                    length = len(v)
                    for i in range(length):
                        if _is_string(v[i]):
                            url = prepend_base(base, v[i])
                            # replace w/@context if requested
                            if replace:
                                ctx = urls[url]
                                if _is_array(ctx):
                                    # add flattened context
                                    v.pop(i)
                                    for e in reversed(ctx):
                                        v.insert(i, e)
                                    i += len(ctx) - 1
                                    length = len(v)
                                else:
                                    v[i] = ctx
                            # @context URL found
                            elif url not in urls:
                                urls[url] = False
                # string @context
                elif _is_string(v):
                    v = prepend_base(base, v)
                    # replace w/@context if requested
                    if replace:
                        input_[k] = urls[v]
                    # @context URL found
                    elif v not in urls:
                        urls[v] = False

    def _retrieve_context_urls(self, input_, cycles, load_document,
                               base: str = ''):
        """
        Retrieves external @context URLs using the given document loader. Each
        instance of @context in the input that refers to a URL will be
        replaced with the JSON @context found at that URL.

        :param input_: the JSON-LD input with possible contexts.
        :param cycles: an object for tracking context cycles.
        :param load_document(url): the document loader.
        :param base: the base URL to resolve relative URLs against.

        :return: the result.
        """
        if len(cycles) > MAX_CONTEXT_URLS:
            raise JsonLdError(
                'Maximum number of @context URLs exceeded.',
                'jsonld.ContextUrlError', {'max': MAX_CONTEXT_URLS},
                code='loading remote context failed')

        # for tracking URLs to retrieve
        urls = {}

        # find all URLs in the given input
        self._find_context_urls(input_, urls, replace=False, base=base)

        # queue all unretrieved URLs
        queue = []
        for url, ctx in urls.items():
            if ctx is False:
                queue.append(url)

        # retrieve URLs in queue
        for url in queue:
            # check for context URL cycle
            if url in cycles:
                raise JsonLdError(
                    'Cyclical @context URLs detected.',
                    'jsonld.ContextUrlError', {'url': url},
                    code='recursive context inclusion')
            cycles_ = copy.deepcopy(cycles)
            cycles_[url] = True

            # retrieve URL
            try:
                remote_doc = load_document(url)
                ctx = remote_doc['document']
            except BaseException as cause:
                raise JsonLdError(
                    'Dereferencing a URL did not result in a valid JSON-LD '
                    'context.',
                    'jsonld.ContextUrlError',  {'url': url},
                    code='loading remote context failed', cause=cause)

            # parse string context as JSON
            if _is_string(ctx):
                try:
                    ctx = json.loads(ctx)
                except BaseException as cause:
                    raise JsonLdError(
                        'Could not parse JSON from URL.',
                        'jsonld.ParseError', {'url': url},
                        code='loading remote context failed', cause=cause)

            # ensure ctx is an object
            if not _is_object(ctx):
                raise JsonLdError(
                    'Dereferencing a URL did not result in a valid JSON-LD '
                    'object.',
                    'jsonld.InvalidUrl', {'url': url},
                    code='invalid remote context')

            # use empty context if no @context key is present
            if '@context' not in ctx:
                ctx = {'@context': {}}
            else:
                ctx = {'@context': ctx['@context']}

            # append context URL to context if given
            if remote_doc['contextUrl'] is not None:
                ctx['@context'] = JsonLdProcessor.arrayify(ctx['@context'])
                ctx['@context'].append(remote_doc['contextUrl'])

            # recurse
            self._retrieve_context_urls(ctx, cycles_, load_document, url)
            urls[url] = ctx['@context']

        # replace all URLs in the input
        self._find_context_urls(input_, urls, replace=True, base=base)

    def _get_initial_context(self, options):
        """
        Gets the initial context.

        :param options: the options to use.
          [base] the document base IRI.

        :return: the initial context.
        """
        return {
            '@base': options['base'],
            'mappings': {},
            'inverse': None
        }

    def _get_inverse_context(self, active_ctx):
        """
        Generates an inverse context for use in the compaction algorithm, if
        not already generated for the given active context.

        :param active_ctx: the active context to use.

        :return: the inverse context.
        """
        # inverse context already generated
        if active_ctx['inverse']:
            return active_ctx['inverse']

        inverse = active_ctx['inverse'] = {}

        # handle default language
        default_language = active_ctx.get('@language', '@none')

        # create term selections for each mapping in the context, ordered by
        # shortest and then lexicographically least
        for term, mapping in sorted(
                active_ctx['mappings'].items(),
                key=cmp_to_key(_compare_shortest_least)):
            if mapping is None:
                continue

            # add term selection where it applies
            container = mapping.get('@container', '@none')

            # iterate over every IRI in the mapping
            iris = JsonLdProcessor.arrayify(mapping['@id'])
            for iri in iris:
                container_map = inverse.setdefault(iri, {})
                entry = container_map.setdefault(
                    container, {'@language': {}, '@type': {}})

                # term is preferred for values using @reverse
                if mapping['reverse']:
                    entry['@type'].setdefault('@reverse', term)
                # term is preferred for values using specific type
                elif '@type' in mapping:
                    entry['@type'].setdefault(mapping['@type'], term)
                # term is preferred for values using specific language
                elif '@language' in mapping:
                    language = mapping['@language']
                    if language is None:
                        language = '@null'
                    entry['@language'].setdefault(language, term)
                # term is preferred for values w/default language or not type
                # and no language
                else:
                    # add an entry for the default language
                    entry['@language'].setdefault(default_language, term)
                    # add entries for no type and no language
                    entry['@type'].setdefault('@none', term)
                    entry['@language'].setdefault('@none', term)

        return inverse

    def _clone_active_context(self, active_ctx):
        """
        Clones an active context, creating a child active context.

        :param active_ctx: the active context to clone.

        :return: a clone (child) of the active context.
        """
        child = {
            '@base': active_ctx['@base'],
            'mappings': copy.deepcopy(active_ctx['mappings']),
            'inverse': None
        }
        if '@language' in active_ctx:
            child['@language'] = active_ctx['@language']
        if '@vocab' in active_ctx:
            child['@vocab'] = active_ctx['@vocab']
        return child


# register the N-Quads RDF parser
register_rdf_parser('application/nquads', JsonLdProcessor.parse_nquads)


class JsonLdError(Exception):
    """
    Base class for JSON-LD errors.
    """

    def __init__(self, message, type_, details=None, code=None, cause=None):
        Exception.__init__(self, message)
        self.type = type_
        self.details = details
        self.code = code
        self.cause = cause
        self.causeTrace = traceback.extract_tb(*sys.exc_info()[2:])

    def __str__(self):
        if not hasattr(self, 'message'):
            return 'Unknown exception'
        rval = repr(self.message)
        rval += '\nType: ' + self.type
        if self.code:
            rval += '\nCode: ' + self.code
        if self.details:
            rval += '\nDetails: ' + repr(self.details)
        if self.cause:
            rval += '\nCause: ' + str(self.cause)
            rval += ''.join(traceback.format_list(self.causeTrace))
        return rval


class UniqueNamer(object):
    """
    A UniqueNamer issues unique names, keeping track of any previously issued
    names.
    """

    def __init__(self, prefix):
        """
        Initializes a new UniqueNamer.

        :param prefix: the prefix to use ('<prefix><counter>').
        """
        self.prefix = prefix
        self.counter = 0
        self.existing = {}
        self.order = []

        """
        Gets the new name for the given old name, where if no old name is
        given a new name will be generated.

        :param [old_name]: the old name to get the new name for.

        :return: the new name.
        """
    def get_name(self, old_name=None):
        # return existing old name
        if old_name and old_name in self.existing:
            return self.existing[old_name]

        # get next name
        name = self.prefix + str(self.counter)
        self.counter += 1

        # save mapping
        if old_name is not None:
            self.existing[old_name] = name
            self.order.append(old_name)

        return name

    def is_named(self, old_name):
        """
        Returns True if the given old name has already been assigned a new
        name.

        :param old_name: the old name to check.

        :return: True if the old name has been assigned a new name, False if
          not.
        """
        return old_name in self.existing


def permutations(elements):
    """
    Generates all of the possible permutations for the given list of elements.

    :param elements: the list of elements to permutate.
    """
    # begin with sorted elements
    elements.sort()
    # initialize directional info for permutation algorithm
    left = {}
    for v in elements:
        left[v] = True

    length = len(elements)
    last = length - 1
    while True:
        yield elements

        # Calculate the next permutation using the Steinhaus-Johnson-Trotter
        # permutation algorithm.

        # get largest mobile element k
        # (mobile: element is greater than the one it is looking at)
        k, pos = None, 0
        for i in range(length):
            e = elements[i]
            is_left = left[e]
            if ((k is None or e > k) and
                    ((is_left and i > 0 and e > elements[i - 1]) or
                     (not is_left and i < last and e > elements[i + 1]))):
                k, pos = e, i

        # no more permutations
        if k is None:
            raise StopIteration

        # swap k and the element it is looking at
        swap = pos - 1 if left[k] else pos + 1
        elements[pos], elements[swap] = elements[swap], k

        # reverse the direction of all elements larger than k
        for i in range(length):
            if elements[i] > k:
                left[elements[i]] = not left[elements[i]]


def _compare_shortest_least(a, b):
    """
    Compares two strings first based on length and then lexicographically.

    :param a: the first string.
    :param b: the second string.

    :return: -1 if a < b, 1 if a > b, 0 if a == b.
    """
    rval = cmp(len(a), len(b))
    if rval == 0:
        rval = cmp(a, b)
    return rval


def _is_keyword(v):
    """
    Returns whether or not the given value is a keyword.

    :param v: the value to check.

    :return: True if the value is a keyword, False if not.
    """
    if not _is_string(v):
        return False
    return v in KEYWORDS


def _is_object(v):
    """
    Returns True if the given value is an Object.

    :param v: the value to check.

    :return: True if the value is an Object, False if not.
    """
    return isinstance(v, dict)


def _is_empty_object(v):
    """
    Returns True if the given value is an empty Object.

    :param v: the value to check.

    :return: True if the value is an empty Object, False if not.
    """
    return _is_object(v) and len(v) == 0


def _is_array(v):
    """
    Returns True if the given value is an Array.

    :param v: the value to check.

    :return: True if the value is an Array, False if not.
    """
    return isinstance(v, list)


def _is_string(v):
    """
    Returns True if the given value is a String.

    :param v: the value to check.

    :return: True if the value is a String, False if not.
    """
    return isinstance(v, basestring)


def _validate_type_value(v):
    """
    Raises an exception if the given value is not a valid @type value.

    :param v: the value to check.
    """
    # must be a string or empty object
    if (_is_string(v) or _is_empty_object(v)):
        return

    # must be an array
    is_valid = False
    if _is_array(v):
        # must contain only strings
        is_valid = True
        for e in v:
            if not _is_string(e):
                is_valid = False
                break

    if not is_valid:
        raise JsonLdError(
            'Invalid JSON-LD syntax; "@type" value must a string, an array of '
            'strings, or an empty object.',
            'jsonld.SyntaxError', {'value': v}, code='invalid type value')


def _is_bool(v):
    """
    Returns True if the given value is a Boolean.

    :param v: the value to check.

    :return: True if the value is a Boolean, False if not.
    """
    return isinstance(v, bool)


def _is_integer(v):
    """
    Returns True if the given value is an Integer.

    :param v: the value to check.

    :return: True if the value is an Integer, False if not.
    """
    return isinstance(v, Integral)


def _is_double(v):
    """
    Returns True if the given value is a Double.

    :param v: the value to check.

    :return: True if the value is a Double, False if not.
    """
    return not isinstance(v, Integral) and isinstance(v, Real)


def _is_numeric(v):
    """
    Returns True if the given value is numeric.

    :param v: the value to check.

    :return: True if the value is numeric, False if not.
    """
    try:
        float(v)
        return True
    except ValueError:
        return False


def _is_subject(v):
    """
    Returns True if the given value is a subject with properties.

    :param v: the value to check.

    :return: True if the value is a subject with properties, False if not.
    """
    # Note: A value is a subject if all of these hold True:
    # 1. It is an Object.
    # 2. It is not a @value, @set, or @list.
    # 3. It has more than 1 key OR any existing key is not @id.
    rval = False
    if (_is_object(v) and
            '@value' not in v and '@set' not in v and '@list' not in v):
        rval = len(v) > 1 or '@id' not in v
    return rval


def _is_subject_reference(v):
    """
    Returns True if the given value is a subject reference.

    :param v: the value to check.

    :return: True if the value is a subject reference, False if not.
    """
    # Note: A value is a subject reference if all of these hold True:
    # 1. It is an Object.
    # 2. It has a single key: @id.
    return (_is_object(v) and len(v) == 1 and '@id' in v)


def _is_value(v):
    """
    Returns True if the given value is a @value.

    :param v: the value to check.

    :return: True if the value is a @value, False if not.
    """
    # Note: A value is a @value if all of these hold True:
    # 1. It is an Object.
    # 2. It has the @value property.
    return _is_object(v) and '@value' in v


def _is_list(v):
    """
    Returns True if the given value is a @list.

    :param v: the value to check.

    :return: True if the value is a @list, False if not.
    """
    # Note: A value is a @list if all of these hold True:
    # 1. It is an Object.
    # 2. It has the @list property.
    return _is_object(v) and '@list' in v


def _is_bnode(v):
    """
    Returns True if the given value is a blank node.

    :param v: the value to check.

    :return: True if the value is a blank node, False if not.
    """
    # Note: A value is a blank node if all of these hold True:
    # 1. It is an Object.
    # 2. If it has an @id key its value begins with '_:'.
    # 3. It has no keys OR is not a @value, @set, or @list.
    rval = False
    if _is_object(v):
        if '@id' in v:
            rval = v['@id'].startswith('_:')
        else:
            rval = (len(v) == 0 or not
                    ('@value' in v or '@set' in v or '@list' in v))
    return rval


def _is_absolute_iri(v):
    """
    Returns True if the given value is an absolute IRI, False if not.

    :param v: the value to check.

    :return: True if the value is an absolute IRI, False if not.
    """
    return ':' in v


class ActiveContextCache(object):
    """
    An ActiveContextCache caches active contexts so they can be reused without
    the overhead of recomputing them.
    """

    def __init__(self, size: int = 100):
        self.order = deque()
        self.cache = {}
        self.size = size

    def get(self, active_ctx, local_ctx):
        key1 = json.dumps(active_ctx)
        key2 = json.dumps(local_ctx)
        return self.cache.get(key1, {}).get(key2)

    def set(self, active_ctx, local_ctx, result):
        if len(self.order) == self.size:
            entry = self.order.popleft()
            del self.cache[entry['activeCtx']][entry['localCtx']]
        key1 = json.dumps(active_ctx)
        key2 = json.dumps(local_ctx)
        self.order.append({'activeCtx': key1, 'localCtx': key2})
        self.cache.setdefault(key1, {})[key2] = json.loads(json.dumps(result))


class VerifiedHTTPSConnection(HTTPSConnection):
    """
    Used to verify SSL certificates when resolving URLs.
    Taken from: http://thejosephturner.com/blog/2011/03/19/https-\
      certificate-verification-in-python-with-urllib2/
    """

    def connect(self):
        global _trust_root_certificates
        # overrides the version in httplib to do certificate verification
        sock = socket.create_connection((self.host, self.port), self.timeout)
        if self._tunnel_host:
            self.sock = sock
            self._tunnel()
        # wrap the socket using verification with trusted_root_certs
        self.sock = ssl.wrap_socket(sock,
                                    self.key_file,
                                    self.cert_file,
                                    cert_reqs=ssl.CERT_REQUIRED,
                                    ca_certs=_trust_root_certificates)


class VerifiedHTTPSHandler(HTTPSHandler):
    """
    Wraps urllib2 HTTPS connections enabling SSL certificate verification.
    """

    def __init__(self, connection_class=VerifiedHTTPSConnection):
        self.specialized_conn_class = connection_class
        HTTPSHandler.__init__(self)

    def https_open(self, req):
        return self.do_open(self.specialized_conn_class, req)


# the path to the system's default trusted root SSL certificates
_trust_root_certificates = None
_possible_trust_root_certificates = [
    '/etc/ssl/certs/ca-certificates.crt',
    '~/Library/OpenSSL/certs/ca-certificates.crt',
    '/System/Library/OpenSSL/certs/ca-certificates.crt',
]
for path in _possible_trust_root_certificates:
    path = os.path.expanduser(path)
    if os.path.exists(path):
        _trust_root_certificates = path
        break
# FIXME: warn if not found?  MacOS X uses keychain vs file.


# Shared in-memory caches.
_cache = {
    'activeCtx': ActiveContextCache()
}
