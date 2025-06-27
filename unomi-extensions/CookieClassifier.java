// Cookie Classifier

// Define CookieCategory enum for compilation
enum CookieCategory {
    ESSENTIAL,
    ANALYTICS,
    ADVERTISING,
    SOCIAL_MEDIA,
    UNKNOWN
}

public class CookieClassifier {
    public CookieCategory classify(String name, String domain, String value) {
        if (isEssentialCookie(name, domain)) {
            return CookieCategory.ESSENTIAL;
        }
        if (isAnalyticsCookie(name, domain)) {
            return CookieCategory.ANALYTICS;
        }
        if (isAdvertisingCookie(name, domain)) {
            return CookieCategory.ADVERTISING;
        }
        if (isSocialMediaCookie(name, domain)) {
            return CookieCategory.SOCIAL_MEDIA;
        }
        return CookieCategory.UNKNOWN;
    }

    // Dummy implementations for compilation
    private boolean isEssentialCookie(String name, String domain) {
        return name != null && (name.toLowerCase().contains("session") || name.toLowerCase().contains("auth"));
    }

    private boolean isAnalyticsCookie(String name, String domain) {
        return name != null && (name.toLowerCase().contains("analytics") || name.toLowerCase().contains("_ga"));
    }

    private boolean isAdvertisingCookie(String name, String domain) {
        return name != null && (name.toLowerCase().contains("ad") || name.toLowerCase().contains("track"));
    }

    private boolean isSocialMediaCookie(String name, String domain) {
        return domain != null && (domain.toLowerCase().contains("facebook") || domain.toLowerCase().contains("twitter"));
    }
}
