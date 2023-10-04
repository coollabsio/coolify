#!/bin/bash
# Sync docker volumes between two servers

VERSION="1.0.0"
SOURCE=$1
DESTINATION=$2
set -e
if [ -z "$SOURCE" ]; then
    echo "Source server is not specified."
    exit 1
fi
if [ -z "$DESTINATION" ]; then
    echo "Destination server is not specified."
    exit 1
fi

SOURCE_USER=$(echo $SOURCE | cut -d@ -f1)
SOURCE_SERVER=$(echo $SOURCE | cut -d: -f1 | cut -d@ -f2)
SOURCE_PORT=$(echo $SOURCE | cut -d: -f2 | cut -d/ -f1)
SOURCE_VOLUME_NAME=$(echo $SOURCE | cut -d/ -f2)

if ! [[ "$SOURCE_PORT" =~ ^[0-9]+$ ]]; then
    echo "Invalid source port: $SOURCE_PORT"
    exit 1
fi

DESTINATION_USER=$(echo $DESTINATION | cut -d@ -f1)
DESTINATION_SERVER=$(echo $DESTINATION | cut -d: -f1 | cut -d@ -f2)
DESTINATION_PORT=$(echo $DESTINATION | cut -d: -f2 | cut -d/ -f1)
DESTINATION_VOLUME_NAME=$(echo $DESTINATION | cut -d/ -f2)

if ! [[ "$DESTINATION_PORT" =~ ^[0-9]+$ ]]; then
    echo "Invalid destination port: $DESTINATION_PORT"
    exit 1
fi

echo "Generating backup file to ./$SOURCE_VOLUME_NAME.tgz"
ssh -p $SOURCE_PORT $SOURCE_USER@$SOURCE_SERVER "docker  run -v $SOURCE_VOLUME_NAME:/volume --rm --log-driver none loomchild/volume-backup backup -c pigz -v" >./$SOURCE_VOLUME_NAME.tgz
echo ""
if [ -f "./$SOURCE_VOLUME_NAME.tgz" ]; then
    echo "Uploading backup file to $DESTINATION_SERVER:~/$DESTINATION_VOLUME_NAME.tgz"
    scp -P $DESTINATION_PORT ./$SOURCE_VOLUME_NAME.tgz $DESTINATION_USER@$DESTINATION_SERVER:~/$DESTINATION_VOLUME_NAME.tgz
    echo ""
    echo "Restoring backup file on remote ($DESTINATION_SERVER:/~/$DESTINATION_VOLUME_NAME.tgz)"
    ssh -p $DESTINATION_PORT $DESTINATION_USER@$DESTINATION_SERVER "docker run -i -v $DESTINATION_VOLUME_NAME:/volume --log-driver none --rm loomchild/volume-backup restore -c pigz -vf < ~/$DESTINATION_VOLUME_NAME.tgz"
    echo ""
    echo "Deleting backup file on remote ($DESTINATION_SERVER:/~/$DESTINATION_VOLUME_NAME.tgz)"
    ssh -p $DESTINATION_PORT $DESTINATION_USER@$DESTINATION_SERVER "rm ~/$DESTINATION_VOLUME_NAME.tgz"

    echo ""
    echo "Local file ./$SOURCE_VOLUME_NAME.tgz is not deleted."

    echo ""
    echo "WARNING: If you are copying a database volume, you need to set the right users/passwords on the destination service's environment variables."
    echo "Why? Because we are copying the volume as-is, so the database credentials will bethe same as on the source volume."
fi

