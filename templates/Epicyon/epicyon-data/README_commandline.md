# Command-line Admin

This system can be administrated from the command-line.

## Account Management

Ordinarily accounts data is stored within a subdirectory of the application directory. This can also be changed using the **accounts-dir** option, which may be used together with other options as needed:

``` bash
python3 epicyon.py --accounts-dir [dir]
```

The first thing you will need to do is to create an account. You can do this with the command:

``` bash
python3 epicyon.py --addaccount nickname@domain --password [yourpassword]
```

You can also leave out the **--password** option and then enter it manually, which has the advantage of passwords not being logged within command history.

To set the nickname for the admin account:

``` bash
python3 epicyon.py --setadmin nickname
```

To remove an account (be careful!):

``` bash
python3 epicyon.py --rmaccount nickname@domain
```

To change the password for an account:

``` bash
python3 epicyon.py --changepassword nickname@domain newpassword
```

To set an avatar for an account:

``` bash
python3 epicyon.py --nickname [nick] --domain [name] --avatar [image filename]
```

To set the background image for an account:

``` bash
python3 epicyon.py --nickname [nick] --domain [name] --background [image filename]
```

## Groups

Groups are a special type of account which relays any posts sent to it to its members (followers).

To create a group:

``` bash
python3 epicyon.py --addgroup nickname@domain --password [yourpassword]
```

To remove an account (be careful!):

``` bash
python3 epicyon.py --rmgroup nickname@domain
```

Setting avatar or changing background is the same as for any other account on the system. You can also moderate a group, applying filters, blocks or a perimeter, in the same way as for other accounts.

## Defining a perimeter

By default the server will federate with any others, but there may be cases where you want to limit this down to a defined set of servers within an organization.

You can specify the domains which can federate with your server with the *--federate* option.

``` bash
python3 epicyon.py --domain [name] --port 8000 --https --federate domain1.net domain2.org domain3.co.uk
```

## Following other accounts

With your server running you can then follow other accounts with:

``` bash
python3 epicyon.py --nickname [yournick] --domain [name] --follow othernick@domain --password [c2s password]
```

The password is for the client to obtain access to the server.

You may or may not need to use the *--port*, *--https* and *--tor* options, depending upon how your server was set up.

Unfollowing is similar:

``` bash
python3 epicyon.py --nickname [yournick] --domain [name] --unfollow othernick@domain --password [c2s password]
```

## Sending posts

To make a public post:

``` bash
python3 epicyon.py --nickname [yournick] --domain [name] \
                   --sendto public --message "hello" \
                   --warning "This is a content warning" \
                   --password [c2s password]
```

To post to followers only:

``` bash
python3 epicyon.py --nickname [yournick] --domain [name] \
                   --sendto followers --message "hello" \
                   --warning "This is a content warning" \
                   --password [c2s password]
```

To send a post to a particular address (direct message):

``` bash
python3 epicyon.py --nickname [yournick] --domain [name] \
                   --sendto othernick@domain --message "hello" \
                   --warning "This is a content warning" \
                   --password [c2s password]
```

The password is the c2s password for your account.

You can also attach an image. It must be in png, jpg or gif format.

``` bash
python3 epicyon.py --nickname [yournick] --domain [name] \
                   --sendto othernick@domain --message "bees!" \
                   --warning "bee-related content" --attach bees.png \
                   --imagedescription "bees on flowers" \
                   --password [c2s password]
```

## Viewing Public Posts

To view the public posts for a person:

``` bash
python3 epicyon.py --posts nickname@domain
```

If you want to view the raw JSON:

``` bash
python3 epicyon.py --postsraw nickname@domain
```

## Getting the JSON for your timelines

The **--posts** option applies for any ActivityPub compatible fediverse account with visible public posts. You can also use an authenticated version to obtain the paginated JSON for your inbox, outbox, direct messages, etc.

``` bash
python3 epicyon.py --nickname [yournick] --domain [yourdomain] --box [inbox|outbox|dm] --page [number] --password [yourpassword]
```

You could use this to make your own c2s client, or create your own notification system.


## Getting the JSON for your blocked items

You can retrieve your current blocklist with:

``` bash
python3 epicyon.py --nickname [yournick] --domain [yourdomain] --page [number] --password [yourpassword] --blocked
```

## Listing referenced domains

To list the domains referenced in public posts:

``` bash
python3 epicyon.py --postDomains nickname@domain
```

## Plotting federated instances

To plot a set of federated instances, based upon a sample of handles on those instances:

``` bash
python3 epicyon.py --socnet nickname1@domain1,nickname2@domain2,nickname3@domain3
xdot socnet.dot
```

## Delete posts

To delete a post which you wrote you must first know its URL. It is usually something like:

``` text
https://yourDomain/users/yourNickname/statuses/number
```

Once you know that they you can use the command:

``` bash
python3 epicyon.py --nickname [yournick] --domain [name] \
                   --delete [url] --password [c2s password]
```

Deletion of posts in a federated system is not always reliable. Some instances may not implement deletion, and this may be because of the possibility of spurious deletes being sent by an adversary to cause trouble.

By default federated deletions are not permitted because of the potential for misuse. If you wish to enable it then set the option **--allowdeletion**.

Another complication of federated deletion is that the followers collection may change between the time when a post was created and the time it was deleted, leaving some stranded copies.

## Announcements/repeats/boosts

To announce or repeat a post you will first need to know it's URL. It is usually something like:

``` text
https://domain/users/name/statuses/number
```

Once you know that they you can use the command:

``` bash
python3 epicyon.py --nickname [yournick] --domain [name] \
                   --repeat [url] --password [c2s password]
```

## Like posts

To like a post you will first need to know it's URL. It is usually something like:

``` text
https://domain/users/name/statuses/number
```

Once you know that they you can use the command:

``` bash
python3 epicyon.py --nickname [yournick] --domain [name] \
                   --like [url] --password [c2s password]
```

To subsequently undo the like:

``` bash
python3 epicyon.py --nickname [yournick] --domain [name] \
                   --undolike [url] --password [c2s password]
```

## Archiving and Expiring posts

As a general rule, all posts will be retained unless otherwise specified. However, on systems with finite and small disk storage running out of space is a show-stopping catastrophe and so clearing down old posts is highly advisable. You can achieve this using the archive commandline option, and optionally also with a cron job.

You can archive old posts and expire posts as specified within account profile settings with:

``` bash
python3 epicyon.py --archive [directory]
```

Which will move old posts to the given directory and delete any expired posts. You can also specify the number of weeks after which images will be archived, and the maximum number of posts within in/outboxes.

``` bash
python3 epicyon.py --archive [directory] --archiveweeks 4 --maxposts 32000
```

If you want old posts to be deleted for data minimization purposes then the archive location can be set to */dev/null*.

``` bash
python3 epicyon.py --archive /dev/null --archiveweeks 4 --maxposts 32000
```

You can put this command into a cron job to ensure that old posts are cleared down regularly. In */etc/crontab* add an entry such as:

``` bash
*/60 * * * * root cd /opt/epicyon && /usr/bin/python3 epicyon.py --archive /dev/null --archiveweeks 4 --maxposts 32000
```

## Blocking and unblocking

Whether you are using the **--federate** option to define a set of allowed instances or not, you may want to block particular accounts even inside of the perimeter. To block an account:

``` bash
python3 epicyon.py --nickname yournick --domain yourdomain --block somenick@somedomain --password [c2s password]
```

This blocks at the earliest possible stage of receiving messages, such that nothing from the specified account will be written to your inbox.

Or to unblock:

``` bash
python3 epicyon.py --nickname yournick --domain yourdomain --unblock somenick@somedomain --password [c2s password]
```

## Bookmarking

You may want to bookmark posts for later viewing or replying. This can be done via c2s with the following:

``` bash
python3 epicyon.py --nickname yournick --domain yourdomain --bookmark [post URL] --password [c2s password]
```

Note that the URL must be that of an ActivityPub post in your timeline. Any other URL will be ignored.

And to undo the bookmark:

``` bash
python3 epicyon.py --nickname yournick --domain yourdomain --unbookmark [post URL] --password [c2s password]
```

## Filtering on words or phrases

Blocking based upon the content of a message containing certain words or phrases is relatively crude and not always effective, but can help to reduce unwanted communications.

To add a word or phrase to be filtered out:

``` bash
python3 epicyon.py --nickname yournick --domain yourdomain --filter "this is a filtered phrase"
```

It can also be removed with:

``` bash
python3 epicyon.py --nickname yournick --domain yourdomain --unfilter "this is a filtered phrase"
```

Like blocking, filters are per account and so different accounts on a server can have differing filter policies.

You can also combine words or phrases with "+", such that they can be present in different parts of the message:

``` bash
python3 epicyon.py --nickname yournick --domain yourdomain --filter "blockedword+some other phrase"
```

## Applying quotas

A common adversarial situation is that a hostile server tries to flood your shared inbox with posts in order to try to overload your system. To mitigate this it's possible to add quotas for the maximum number of received messages per domain per day and per account per day.

If you're running the server it would look like this:

``` bash
python3 epicyon.py --domainmax 1000 --accountmax 200
```

With these settings you're going to be receiving no more than 200 messages for any given account within a day.


## Assigning skills

To help create organizations you can assign some skills to your account. Note that you can only assign skills to yourself and not to other people. The command is:

``` bash
python3 epicyon.py --nickname [nick] --domain [mydomain] \
                   --skill [tag] --level [0-100] \
                   --password [c2s password]
```

The level value is a percentage which indicates how proficient you are with that skill.

This extends the ActivityPub client-to-server protocol to include an activity called *Skill*. The JSON looks like:

``` json
{ 'type': 'Skill',
  'actor': https://somedomain/users/somenickname,
  'object': gardening;80,
  'to': [],
  'cc': []}
```

## Setting availability status

For the purpose of things like knowing current task status or task completion a status value can be set.

``` bash
python3 epicyon.py --nickname [nick] --domain [mydomain] \
                   --availability [status] \
                   --password [c2s password]
```

The status value can be any string, and can become part of organization building by combining it with roles and skills.

This extends the ActivityPub client-to-server protocol to include an activity called *Availability*. "Status" was avoided because of the possibility of confusion with other things. The JSON looks like:

``` json
{ 'type': 'Availability',
  'actor': https://somedomain/users/somenickname,
  'object': ready,
  'to': [],
  'cc': []}
```

## Shares

This system includes a feature for bartering or gifting (i.e. common resource pooling or exchange without money), based upon the earlier Sharings plugin made by the Las Indias group which existed within GNU Social. It's intended to operate at the municipal level, sharing physical objects with people in your local vicinity. For example, sharing gardening tools on a street or a 3D printer between maker-spaces.

To share an item.

``` bash
python3 epicyon.py --itemName "spanner" --nickname [yournick] --domain [yourdomain] --summary "It's a spanner" --itemType "tool" --itemCategory "mechanical" --location [yourCity] --duration "2 months" --itemImage spanner.png --password [c2s password]
```

For the duration of the share you can use hours, days, weeks, months, or years.

To remove a shared item:

``` bash
python3 epicyon.py --undoItemName "spanner" --nickname [yournick] --domain [yourdomain] --password [c2s password]
```

## Calendar

The calendar for each account can be accessed via CalDav (RFC4791). This makes it easy to integrate the social calendar into other applications. For example, to obtain events for a month:

```bash
python3 epicyon.py --dav --nickname [yournick] --domain [yourdomain] --year [year] --month [month number]
```

You will be prompted for your login password, or you can use the **--password** option. You can also use the **--day** option to obtain events for a particular day.

The CalDav endpoint for an account is:

```bash
yourdomain/calendars/yournick
```

## Web Crawlers

Having search engines index social media posts is not usually considered appropriate, since even if "public" they may contain personally identifiable information. If you are running a news instance then web crawlers will be permitted by the system, but otherwise by default they will be blocked.

If you want to allow specific web crawlers then when running the daemon (typically with systemd) you can use the **crawlersAllowed** option. It can take a list of bot names, separated by commas. For example:

```bash
--crawlersAllowed "googlebot, apple"
```

Typically web crawlers have names ending in "bot", but partial names can also be used.


## Image watermarks and generative AI

When attaching an image to a new post it is possible to have a watermark image stamped onto it. This helps to mess with generative "AI" scrapers, using images improperly without permission, attribution or financial compensation.

The watermark image can be set via editing your profile and then going to the **background images** section. Once that is set you can also use daemon options as follows to determine how the watermark is displayed.

```bash
--watermarkWidthPercent 30 --watermarkPosition east --watermarkOpacity 10
```

The opacity is a percentage value, and you could potentially use 100% opacity together with a watermark which has a transparent background.

The watermark position is a compass direction: north, south, east, west, or combinations thereof. It can also be set to "random" to choose a random position. Random positioning will make it more difficult for any scraper bot to try to remove.

Even if the scraper bot tries to remove your watermark from the image by filling in from the surrounding pixels, the removal itself may leave a detectable trace indicative of improper use.


## Instance software type

You can find out what software an instance is running with:

```bash
--software [domain]
```

So for example **--software mastodon.social** returns **mastodon**.


## Maintenance

You can check for any novel ActivityPub fields with:

```bash
--novel
```

This may help with spotting unconventional uses of the protocol, or back channel signalling between admins.
