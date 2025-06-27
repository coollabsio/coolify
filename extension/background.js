import { UnomiTracker } from './unomi-integration.js';

const unomi = new UnomiTracker({
  endpoint: "https://profile.iname.cx",
  profileId: await getProfileId(), // From Auth.lat
  privacyLevel: await getPrivacySetting() // User-configured
});

// Track all web activity
chrome.webNavigation.onCompleted.addListener(details => {
  unomi.trackPageView({
    url: details.url,
    title: details.tab.title,
    timestamp: new Date().getTime()
  });
});

// Monitor cookies in real-time
chrome.cookies.onChanged.addListener(changeInfo => {
  if (!changeInfo.removed) {
    unomi.trackCookieSet({
      name: changeInfo.cookie.name,
      domain: changeInfo.cookie.domain,
      purpose: "unknown",
      category: await classifyCookie(changeInfo.cookie)
    });
  }
});

// Automatic GDPR enforcement
chrome.webRequest.onBeforeSendHeaders.addListener(
  details => {
    if (shouldBlockRequest(details)) {
      return { cancel: true };
    }
    return { requestHeaders: applyPrivacyHeaders(details.requestHeaders) };
  },
  { urls: ["<all_urls>"] },
  ["blocking", "requestHeaders"]
);
