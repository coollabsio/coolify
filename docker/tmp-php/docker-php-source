#!/bin/sh
set -e

dir=/usr/src/php

usage() {
	echo "usage: $0 COMMAND"
	echo
	echo "Manage php source tarball lifecycle."
	echo
	echo "Commands:"
	echo "   extract  extract php source tarball into directory $dir if not already done."
	echo "   delete   delete extracted php source located into $dir if not already done."
	echo
}

case "$1" in
	extract)
		mkdir -p "$dir"
		if [ ! -f "$dir/.docker-extracted" ]; then
			tar -Jxf /usr/src/php.tar.xz -C "$dir" --strip-components=1
			touch "$dir/.docker-extracted"
		fi
		;;

	delete)
		rm -rf "$dir"
		;;

	*)
		usage
		exit 1
		;;
esac
