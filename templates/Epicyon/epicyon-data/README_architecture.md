# Epicyon Software Architecture

## Design Constraints

### Open Standards Compliance

Follow the standards for HTML, CSS and ActivityPub. Especially with ActivityPub there is always some room for interpretation, so if in doubt about a protocol implementation detail then do whatever Mastodon does to maintain maximum compatibility.

### Multi-User

It is assumed that an instance may have multiple users, although the maximum number of users is not expected to be very high. This system is for a "family and friends" or small club type of scenario.

Although it can be single user, this is not strictly a single user system.

### Opinionated

The design of this system is opinionated, and to a large extent informed by years of past experience in the fediverse. There is no claim to neutrality of any sort. Automatic removal of hellthreads and other common griefing tactics is an example of this.

### Privacy Sensitive Defaults

Follow approval should be required by default. This gives the user a chance to see who wants to follow them and make a decision. Also by default direct messages should not be permitted except with accounts that you are following. This helps to reduce spam and harrassment from random accounts in the wider fediverse. The aim is for the user to have a good experience by default, even if they have not yet built up any sort of block list.

### Resisting Centralization

Centralization is characterized by the typical fixation upon "scale" within the software industry. Systems which scale, in the way which is commonly understood, mean that a few individuals can control the social lives of many, and extract value from them in often cynical and manipulative ways.

In general, methods have been preferred which do not vertically scale. This includes the decision not to use a database, and the way that the inbox is processed. Lack of scalability also simplifies the design.

Being hostile towards the common notion of scaling means that this system will be of no interest to "big tech" and can't easily be used within extractive economic models without needing a substantial rewrite. This avoids the typical cooption strategies in which large companies eventually take over what was originally software developed by grassroots activists to address real community needs.

This system should however be able to scale rhizomatically with the deployment of many small instances federated together. Instead of scaling up, scale out. In a network of many small instances nobody has overall control and corporate capture is far less feasible. Small instances also minimize the bureaucratic requirements for governance processes, which at medium to large scale eventually becomes tyrannical.

### Discourage public instances

That is, public instances containing large numbers of users. People using this system should be self-hosting, or very small scale hosting with typically less than ten users. At larger scales this system should simply cease to be practical, due to the design choices made. This is not a system for constructing empires or personality cults.

### Roles

The roles within an instance are comparable to the crew roles onboard a ship, with the admin being its captain. Delegation is minimal, with the admin assigning roles to particular user accounts. Avoiding delegation prevents a hierarchy of roles from forming. Social organization should be as horizontal as possible. Roles could be rotated - even including that of admin - although there is no technical mechanism requiring that.

### No Javascript

This is so that the system can be accessed and used normally with javascript in the web browser turned off. If you want to have good security then this is useful, since lack of javascript greatly reduces the attack surface and constrains adversaries to a limited number of vectors. Not using javascript also makes this system usable in shell based browsers such as Lynx, or other less common browsers, which helps to avoid being locked in to a browser duopoly.

### Block Crawlers

Ordinarily web crawlers would not be a problem, but in the context of a social network even having crawlers index public posts can create ethical dilemmas in some circumstances. News and blogging instances may allow crawlers, but other types of instances should block them.

### Poison Scrapers

Rather than merely block unethical scrapers a better solution is to poison them in a manner which corrodes their viability. So by supplying text with the statistical profile of natural language but which is semantically worthless to known "AI" scrapers means that they then need to perform manual review to keep this data out of their training set, which decreases their profit. Ingesting random data bends generative AI models correspondingly towards entropy. As happens in nature, make yourself unappetising to predators.

### No Local or Federated Timelines

The local and federated timelines of other ActivityPub servers don't add much value (especially the federated one), and tend to pollute the default timeline with irrelevant posts from people that you don't follow.

Especially on a small instance with a few users, the local timeline would not be significantly useful.

### Notification handling is out of scope

There are no notifications in the conventional sense. That is, there is no streaming API or linkage to browser notifications. Instead when significant events occur these create text files which can then be detected by other systems via polling.

See *scripts/epicyon-notifications* for an example of a script which could be run in a cron job to then send notifications via XMPP or Matrix.

### Assume Network Hostility

Many of the early web systems existed in a twee world in which it was assumed that everyone is nice, but in social networks this is rarely true.

It is usually safe to assume that the federated network beyond your instance is to a lesser or greater degree hostile. So there should be effective controls for blocking adversaries or spam floods.

### Limited Linked Data Support

Where Json linked data signatures are supported there should not be arbitrary schema lookups via the web. Instead, recognized contexts should be added to *context.py*. This is in order to follow the principle of *no processing without full recognition*, in which the recognition step is not endlessly extendable by untrusted parties.

### Avoid Web Frameworks

In general avoid using web frameworks and instead use local modules which are prefixed with *webapp_*. Web frameworks are built for conventional software engineering by large companies who are designing for scale. They typically have database dependencies and contain a lot of hardcoded Google stuff or other things which will leak metadata or be incompatible with onion routing. Keeping up with web frameworks is a constant firefight. They also create a massive attack surface requiring constant vigilance. Another attack vector is via deserialization functions buried within common web frameworks.

## High Level Architecture

The main modules are *epicyon.py* and *daemon.py*. *epicyon.py* is the commandline interface and *daemon.py* is the http server. The daemon has submodules for HTTP GET and HTTP POST.

![commandline and daemon modules](https://gitlab.com/bashrc2/epicyon/-/raw/main/architecture/epicyon_groups_Commandline-Interface_Daemon.png)

The daemon runs the inbox queue in a separate thread (see *inbox.py*) and the inbox queue processes incoming ActivityPub posts one at a time in a strictly serial fashion. Doing it this way means minimum potential for any parallelism/locking issues. It also means that the inbox queue is not highly scalable, but that's ok for a system which is only intended to have a few users per instance.

All ActivityPub posts are stored as text files, and there is no database as such other than the filesystem itself. Think of it as being like an email server. Each post is a json file stored in *accounts/nick@domain/inbox* or *accounts/nick@domain/outbox*. To avoid parsing problems slashes are replaced by hashes within the ActivityPub post filename. The filename for each post is the same as its ActivityPub id.

![timeline and daemon modules](https://gitlab.com/bashrc2/epicyon/-/raw/main/architecture/epicyon_groups_Timeline_Daemon.png)


## Security

### Themes

It is possible to include arbitrary CSS within a custom theme. To avoid security problems the CSS is sanitized before being used. Scripts or import references to other CSS files are not permitted.

The way that the theming system was designed is in order to avoid problems similar to Wordpress, in which an adversary will create an attactive looking theme which contains an exploit. The discovery of exploits then leads to a centralizing dynamic where there is a single "official" themes website or app store. With Epicyon, *themes should always be safe to use no matter where they were downloaded from*. There should be nothing *Turing complete* within a theme.

### C2S

This currently uses basic auth, which is simple to implement. Oauth2 is conventional, but seems overly complex and the user interface for it within other comparable apps is clunky.

### Interaction with Timeline

![timeline and security modules](https://gitlab.com/bashrc2/epicyon/-/raw/main/architecture/epicyon_groups_Timeline_Security.png)

The *inbox* queue makes calls to check http and linked data signatures. Various modules call *auth* typically because they're implementing the basic auth of the C2S interface.

![timeline and daemon modules](https://gitlab.com/bashrc2/epicyon/-/raw/main/architecture/epicyon_groups_Timeline_Daemon-Timeline_Daemon.png)

## Accessibility

Trying to keep up with web accessibility standards. There should be configurable keyboard shortcuts for all of the main navigation actions. High contrast themes should be available. The desktop client should support text-to-speech. There should be the ability to run in a shell browser such as Lynx, without any significant loss of functionality.

Avoid adding any features which would be hard to make accessible.

![web interface and accessibility modules](https://gitlab.com/bashrc2/epicyon/-/raw/main/architecture/epicyon_groups_Web-Interface_Accessibility.png)

The *webapp_post* module generates html for each post from its ActivityPub json representation. This also calls the speaker module in order to create a text-to-speech friendly version of the post content, which can then be spoken by the desktop client. Doing this allows common acronyms and other special language to be properly pronounced.

The *daemon* module (http server) also calls *webapp_accesskeys* to display the key shortcuts screen.

![daemon and accessibility modules](https://gitlab.com/bashrc2/epicyon/-/raw/main/architecture/epicyon_groups_Daemon_Accessibility.png)
