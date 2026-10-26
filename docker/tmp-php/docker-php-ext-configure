#!/bin/sh
set -e

# prefer user supplied CFLAGS, but default to our PHP_CFLAGS
: ${CFLAGS:=$PHP_CFLAGS}
: ${CPPFLAGS:=$PHP_CPPFLAGS}
: ${LDFLAGS:=$PHP_LDFLAGS}
export CFLAGS CPPFLAGS LDFLAGS

srcExists=
if [ -d /usr/src/php ]; then
	srcExists=1
fi
docker-php-source extract
if [ -z "$srcExists" ]; then
	touch /usr/src/php/.docker-delete-me
fi

cd /usr/src/php/ext

usage() {
	echo "usage: $0 ext-name [configure flags]"
	echo "   ie: $0 gd --with-jpeg-dir=/usr/local/something"
	echo
	echo 'Possible values for ext-name:'
	find . \
			-mindepth 2 \
			-maxdepth 2 \
			-type f \
			-name 'config.m4' \
		| xargs -n1 dirname \
		| xargs -n1 basename \
		| sort \
		| xargs
	echo
	echo 'Some of the above modules are already compiled into PHP; please check'
	echo 'the output of "php -i" to see which modules are already loaded.'
}

ext="$1"
if [ -z "$ext" ] || [ ! -d "$ext" ]; then
	usage >&2
	exit 1
fi
shift

pm='unknown'
if [ -e /lib/apk/db/installed ]; then
	pm='apk'
fi

if [ "$pm" = 'apk' ]; then
	if \
		[ -n "$PHPIZE_DEPS" ] \
		&& ! apk info --installed .phpize-deps > /dev/null \
		&& ! apk info --installed .phpize-deps-configure > /dev/null \
	; then
		apk add --no-cache --virtual .phpize-deps-configure $PHPIZE_DEPS
	fi
fi

if command -v dpkg-architecture > /dev/null; then
	gnuArch="$(dpkg-architecture --query DEB_BUILD_GNU_TYPE)"
	set -- --build="$gnuArch" "$@"
fi

cd "$ext"
phpize
./configure --enable-option-checking=fatal "$@"
