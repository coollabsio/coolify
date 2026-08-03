/**
 * Color utility functions for the application
 */
export function initializeColorUtils() {
    /**
     * Determines the appropriate text color (black or white) based on background color luminance
     * Uses WCAG relative luminance formula for accessibility
     *
     * @param {string} bgColor - Hex color code (e.g., '#FF5733')
     * @returns {string} Tailwind CSS class: 'text-black' or 'text-white'
     */
    function getContrastTextColor(bgColor) {
        if (!bgColor) return '';

        // Convert hex to RGB
        const hex = bgColor.replace('#', '');
        const r = parseInt(hex.substr(0, 2), 16);
        const g = parseInt(hex.substr(2, 2), 16);
        const b = parseInt(hex.substr(4, 2), 16);

        // Calculate relative luminance using WCAG formula
        const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;

        // Return dark text for light backgrounds, light text for dark backgrounds
        return luminance > 0.5 ? 'text-black' : 'text-white';
    }

    // Make it globally available
    window.getContrastTextColor = getContrastTextColor;
}
