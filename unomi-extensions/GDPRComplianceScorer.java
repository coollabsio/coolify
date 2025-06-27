// GDPR Compliance Evaluator
public class GDPRComplianceScorer {
    public double calculateComplianceScore(SiteVisit visit) {
        double score = 100;

        // Deduct points for violations
        if (!visit.hasConsentBanner()) score -= 30;
        if (visit.hasNonEssentialCookiesWithoutConsent()) score -= 25;
        if (visit.hasHiddenTrackers()) score -= 20;
        if (!visit.hasPrivacyPolicyLink()) score -= 15;
        if (visit.hasDarkPatterns()) score -= 10;

        // Bonus for good practices
        if (visit.hasExplicitConsentOptions()) score += 10;
        if (visit.respectsGlobalPrivacyControl()) score += 15;

        return Math.max(0, score);
    }
}

// Dummy SiteVisit class for compilation
class SiteVisit {
    public boolean hasConsentBanner() { return true; }
    public boolean hasNonEssentialCookiesWithoutConsent() { return false; }
    public boolean hasHiddenTrackers() { return false; }
    public boolean hasPrivacyPolicyLink() { return true; }
    public boolean hasDarkPatterns() { return false; }
    public boolean hasExplicitConsentOptions() { return true; }
    public boolean respectsGlobalPrivacyControl() { return true; }
}
