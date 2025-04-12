# C2S Desktop client

<img src="https://libreserver.org/epicyon/img/desktop_client.jpg" width="80%"/>

## Installing and running

You can install the desktop client with:

``` bash
./install-desktop-client
```

and run it with:

``` bash
~/epicyon-client
```

To run it with text-to-speech via espeak:

``` bash
~/epicyon-client-tts
```

Or if you have picospeaker installed:

``` bash
~/epicyon-client-pico
```

Or if you have mimic3 installed:

``` bash
~/epicyon-client-mimic3
```

## Commands

The desktop client has a few commands, which may be more convenient than the web interface for some purposes:

``` bash
quit                                  Exit from the desktop client
mute                                  Turn off the screen reader
speak                                 Turn on the screen reader
sounds on                             Turn on notification sounds
sounds off                            Turn off notification sounds
rp                                    Repeat the last post
like                                  Like the last post
unlike                                Unlike the last post
bookmark                              Bookmark the last post
unbookmark                            Unbookmark the last post
block [post number|handle]            Block someone via post number or handle
unblock [handle]                      Unblock someone
mute                                  Mute the last post
unmute                                Unmute the last post
reply                                 Reply to the last post
post                                  Create a new post
post to [handle]                      Create a new direct message
announce/boost                        Boost the last post
follow [handle]                       Make a follow request
unfollow [handle]                     Stop following the give handle
show dm|sent|inbox|replies|bookmarks  Show a timeline
next                                  Next page in the timeline
prev                                  Previous page in the timeline
read [post number]                    Read a post from a timeline
open [post number]                    Open web links within a timeline post
profile [post number or handle]       Show profile for the person who made the given post
following [page number]               Show accounts that you are following
followers [page number]               Show accounts that are following you
approve [handle]                      Approve a follow request
deny [handle]                         Deny a follow request
pgp                                   Show your PGP public key
```

If you have a GPG key configured on your local system and are sending a direct message to someone who has a PGP key (the exported key, not just the key ID) set as a tag on their profile then it will try to encrypt the message automatically. So under some conditions end-to-end encryption is possible, such that the instance server only sees ciphertext. Conversely, for arriving direct messages if they are PGP encrypted then the desktop client will try to obtain the relevant public key and decrypt.

## Speaking your inbox

It is possible to use text-to-speech to read your inbox as posts arrive. This can be useful if you are not looking at a screen but want to stay ambiently informed of what's happening.

On Debian based systems you will need to have the **python3-espeak** package installed.

``` bash
python3 epicyon.py --notifyShowNewPosts --screenreader espeak --desktop yournickname@yourdomain
```

Or a quicker version, if you have installed the desktop client as described above.

``` bash
~/epicyon-client-stream
```

Or if you have [picospeaker](https://gitlab.com/ky1e/picospeaker) installed:

``` bash
~/epicyon-stream-pico
```

Or if you have mimic3 installed:

``` bash
~/epicyon-stream-mimic3
```

You can also use the **--password** option to provide the password. This will then stay running and incoming posts will be announced as they arrive.
