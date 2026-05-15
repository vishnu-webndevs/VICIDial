/**
 * Timezone detection and formatting utilities
 * Detects user's timezone automatically based on browser locale
 */

/**
 * Detect user's timezone using the Intl API
 * Returns the timezone identifier (e.g., "America/New_York", "Europe/London")
 */
export function detectUserTimezone(): string {
    try {
        // Using Intl.DateTimeFormat to get the system timezone
        const format = new Intl.DateTimeFormat("en-US", {
            timeZone: undefined,
        });
        const formatWithTZ = new Intl.DateTimeFormat("en-US", {
            timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
            hour12: false,
        });

        // Get timezone from resolved options (most reliable method)
        const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        return timeZone || "UTC";
    } catch (error) {
        console.warn("Failed to detect timezone, falling back to UTC", error);
        return "UTC";
    }
}

/**
 * Get the offset of the user's timezone from UTC in minutes
 */
export function getTimezoneOffset(): number {
    const now = new Date();
    // Get UTC time
    const utcDate = new Date(now.toLocaleString("en-US", { timeZone: "UTC" }));
    // Get local time
    const localDate = new Date(now.toLocaleString("en-US"));
    // Calculate offset in minutes
    return (localDate.getTime() - utcDate.getTime()) / (1000 * 60);
}

/**
 * Get timezone abbreviation (e.g., EST, PST, GMT)
 */
export function getTimezoneAbbreviation(): string {
    try {
        const parts = new Intl.DateTimeFormat("en-US", {
            timeZoneName: "short",
        }).formatToParts(new Date());

        const tzPart = parts.find((p) => p.type === "timeZoneName");
        return tzPart?.value || "UTC";
    } catch (error) {
        console.warn("Failed to get timezone abbreviation", error);
        return "UTC";
    }
}

/**
 * Get timezone name (long format)
 */
export function getTimezoneName(): string {
    try {
        const parts = new Intl.DateTimeFormat("en-US", {
            timeZoneName: "long",
        }).formatToParts(new Date());

        const tzPart = parts.find((p) => p.type === "timeZoneName");
        return tzPart?.value || "UTC";
    } catch (error) {
        console.warn("Failed to get timezone name", error);
        return "UTC";
    }
}

/**
 * Common timezone options for fallback
 * Used if auto-detection doesn't match perfectly
 */
export const COMMON_TIMEZONES = [
    "UTC",
    "America/New_York",
    "America/Chicago",
    "America/Denver",
    "America/Los_Angeles",
    "America/Anchorage",
    "Pacific/Honolulu",
    "Europe/London",
    "Europe/Paris",
    "Europe/Berlin",
    "Europe/Moscow",
    "Asia/Dubai",
    "Asia/Kolkata",
    "Asia/Bangkok",
    "Asia/Hong_Kong",
    "Asia/Tokyo",
    "Asia/Seoul",
    "Asia/Shanghai",
    "Asia/Manila",
    "Australia/Sydney",
    "Australia/Melbourne",
    "Pacific/Auckland",
    "Canada/Eastern",
    "Canada/Central",
    "Canada/Mountain",
    "Canada/Pacific",
    "Brazil/East",
    "Mexico/General",
    "Africa/Cairo",
    "Africa/Johannesburg",
    "Africa/Lagos",
    "India/Standard",
    "Middle_East/Beirut",
    "Singapore",
    "Hong_Kong",
] as const;

/**
 * Validate if a given timezone string is valid
 */
export function isValidTimezone(tz: string): boolean {
    try {
        new Intl.DateTimeFormat("en-US", { timeZone: tz });
        return true;
    } catch {
        return false;
    }
}

/**
 * Format a date/time in a specific timezone
 * Uses Intl.DateTimeFormat for reliability
 */
export function formatDateInTimezone(
    date: Date,
    timezone: string,
    options?: Intl.DateTimeFormatOptions
): string {
    try {
        const defaultOptions: Intl.DateTimeFormatOptions = {
            year: "numeric",
            month: "short",
            day: "numeric",
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
            timeZone: timezone,
            ...options,
        };

        return new Intl.DateTimeFormat("en-US", defaultOptions).format(date);
    } catch (error) {
        console.warn(`Failed to format date in timezone ${timezone}`, error);
        return date.toISOString();
    }
}

/**
 * Get timezone info object
 */
export function getTimezoneInfo() {
    const detected = detectUserTimezone();
    const offset = getTimezoneOffset();
    const abbreviation = getTimezoneAbbreviation();
    const name = getTimezoneName();

    return {
        timezone: detected,
        offset,
        abbreviation,
        name,
        isDaylightSaving: offset !== 0 && offset !== (5 * 60 + 30), // Rough heuristic
    };
}
