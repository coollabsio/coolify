# Epicyon Project Goals

 * A minimal ActivityPub server, comparable to an email MTA
 * "Small Tech" ethos. Not many accounts per instance
 * Centering people and personal computing, not corporate or organizational accounts abstracting people away
 * AGPLv3+
 * Server-to-server and client-to-server protocols supported
 * Implemented in a common language (Python 3)
 * Keyword filtering
 * Attention to accessibility and should be usable in lynx with a screen reader
 * Remove metadata from attached images, avatars and backgrounds
 * Support for multiple themes, with ability to create custom themes
 * Being able to build crowd-sourced organizations with roles and skills
 * Sharings collection, similar to the gnusocial sharings plugin
 * Quotas for received posts per day, per domain and per account
 * Hell-thread detection and removal
 * Instance and account level federation lists
 * Support content warnings, reporting and blocking
 * http signatures and basic auth
 * JSON-LD signatures on outgoing posts, optional on incoming
 * Compatible with HTTP (onion addresses, i2p) and HTTPS
 * Minimal dependencies
 * Dependencies are maintained Debian packages
 * Data minimization principle. Configurable post expiry time
 * Likes and repeats only visible to authorized viewers
 * Reply Guy mitigation - maximum replies per post or posts per day
 * Ability to delete or hide specific conversation threads
 * Command-line interface
 * Simple web interface
 * Designed for intermittent connectivity. Assume network disruptions
 * Limited visibility of follows/followers
 * Suitable for single board computers
 * Progressive Web App interface. Doesn't need native apps on mobile
 * Integration with RSS feeds, for reading news or blogs
 * Moderation capabilities for posts, hashtags and blocks
 * Federated blocklist support

## Non-goals

The following are considered anti-features of other social network systems, since they encourage dysfunctional social interactions.

 * Features designed to scale to large numbers of accounts (say, more than 20 active users)
 * Trending hashtags, or trending anything
 * Ranking, rating or recommending mechanisms for posts or people (other than likes or repeats/boosts)
 * Geo-location features, unless they're always opt-in
 * Algorithmic timelines (i.e. non-chronological)
 * Direct payment mechanisms, although integration with other services may be possible
 * Any variety of blockchain
 * Non Fungible Token (NFT) features
 * Anything based upon "proof of stake". The "people who have more, get more" principle should be rejected.
 * Like counts above some small maximum number. The aim is to avoid people getting addicted to making numbers go up, and especially to avoid the dark market in fake likes.
 * Sponsored posts
 * Enterprise features for use cases applicable only to businesses. Epicyon could be used in a small business, but it's not primarily designed for that
 * Collaborative editing of posts, although you could do that outside of this system using Etherpad, or similar
 * Anonymous posts from random internet users published under a single generic instance account. "Spam enabling features". De-personalisation.
 * Hierarchies of roles beyond ordinary moderation, such as X requires special agreement from Y before sending a post. Originally delegated roles were envisioned, but later abandoned due to the potential for creating elaborate hierarchies
 * Federated moderation. Under realistic conditions people could be pressured or bribed into giving federated moderation access, and the consequences could be very bad. Individuals going on power trips, controlling multiple instances and heading back towards centralization. Avoid creating technical routes which easily lead to power consolidation and centralization.
