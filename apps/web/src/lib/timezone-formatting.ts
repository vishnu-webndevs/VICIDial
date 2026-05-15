/**
 * Timezone formatting utilities for displaying dates/times in user's timezone
 * Works with dayjs and native Intl API
 */

import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import timezone from "dayjs/plugin/timezone";
import relativeTime from "dayjs/plugin/relativeTime";

// Enable timezone support in dayjs
dayjs.extend(utc);
dayjs.extend(timezone);
dayjs.extend(relativeTime);

// Type augmentation for dayjs with plugins
declare global {
    namespace dayjs {
        interface Dayjs {
            tz(tz?: string, keepLocalTime?: boolean): Dayjs;
            utc(keepLocalTime?: boolean): Dayjs;
            fromNow(): string;
        }
    }
}

/**
 * Format a date/time string in the user's detected timezone
 * 
 * @example
 * const formatted = formatInUserTimezone("2024-01-15T10:30:00Z", "America/New_York");
 * // Output: "Jan 15, 2024 5:30 AM EST"
 */
export function formatInUserTimezone(
    dateString: string | Date,
    userTimezone: string,
    format: string = "MMM DD, YYYY h:mm A z"
): string {
    try {
        return dayjs(dateString).tz(userTimezone).format(format);
    } catch (error) {
        console.warn(`Failed to format date in timezone ${userTimezone}`, error);
        return dayjs(dateString).format("YYYY-MM-DD HH:mm");
    }
}

/**
 * Get relative time in user's timezone
 * 
 * @example
 * const relative = getRelativeTimeInTimezone("2024-01-15T10:30:00Z", "America/New_York");
 * // Output: "3 hours ago"
 */
export function getRelativeTimeInTimezone(
    dateString: string | Date,
    userTimezone: string
): string {
    try {
        return dayjs(dateString).tz(userTimezone).fromNow();
    } catch (error) {
        console.warn(`Failed to get relative time in timezone ${userTimezone}`, error);
        return dayjs(dateString).fromNow();
    }
}

/**
 * Convert a time from one timezone to another
 * 
 * @example
 * const converted = convertTimezone("10:30", "America/New_York", "Europe/London");
 * // Output: "03:30"
 */
export function convertTimezone(
    time: string,
    fromTimezone: string,
    toTimezone: string,
    dateString?: string
): string {
    try {
        const baseDate = dateString ? dayjs(dateString) : dayjs();
        const [hours, minutes] = time.split(":").map(Number);
        const dateInFromTz = baseDate
            .tz(fromTimezone)
            .set("hour", hours)
            .set("minute", minutes || 0);
        return dateInFromTz.tz(toTimezone).format("HH:mm");
    } catch (error) {
        console.warn(`Failed to convert timezone from ${fromTimezone} to ${toTimezone}`, error);
        return time;
    }
}

/**
 * Get the current time in a specific timezone
 * 
 * @example
 * const now = getCurrentTimeInTimezone("America/New_York");
 * // Output: "Jan 15, 2024 3:45 PM EST"
 */
export function getCurrentTimeInTimezone(
    userTimezone: string,
    format: string = "MMM DD, YYYY h:mm A z"
): string {
    try {
        return dayjs().tz(userTimezone).format(format);
    } catch (error) {
        console.warn(`Failed to get current time in timezone ${userTimezone}`, error);
        return dayjs().format("YYYY-MM-DD HH:mm");
    }
}

/**
 * Get timezone offset from UTC (e.g., "+05:30", "-08:00")
 * 
 * @example
 * const offset = getTimezoneOffsetString("America/New_York");
 * // Output: "-05:00" or "-04:00" (depending on DST)
 */
export function getTimezoneOffsetString(userTimezone: string): string {
    try {
        return dayjs().tz(userTimezone).format("Z");
    } catch (error) {
        console.warn(`Failed to get timezone offset for ${userTimezone}`, error);
        return "Z";
    }
}

/**
 * Check if a timezone is currently observing daylight saving time
 * 
 * @example
 * const isDST = isObservingDST("America/New_York");
 */
export function isObservingDST(userTimezone: string): boolean {
    try {
        // Compare offset between January and July
        const jan = dayjs("2024-01-15").tz(userTimezone);
        const jul = dayjs("2024-07-15").tz(userTimezone);
        return jan.utcOffset() !== jul.utcOffset();
    } catch (error) {
        console.warn(`Failed to check DST for ${userTimezone}`, error);
        return false;
    }
}

/**
 * Format a time range in user's timezone
 * 
 * @example
 * const range = formatTimeRange("09:00", "17:00", "America/New_York");
 * // Output: "9:00 AM - 5:00 PM EST"
 */
export function formatTimeRange(
    startTime: string,
    endTime: string,
    userTimezone: string
): string {
    try {
        const now = dayjs().tz(userTimezone);
        const [startHours, startMinutes] = startTime.split(":").map(Number);
        const [endHours, endMinutes] = endTime.split(":").map(Number);

        const start = now.set("hour", startHours).set("minute", startMinutes || 0);
        const end = now.set("hour", endHours).set("minute", endMinutes || 0);

        const tzAbbr = now.format("z");
        return `${start.format("h:mm A")} - ${end.format("h:mm A")} ${tzAbbr}`;
    } catch (error) {
        console.warn(`Failed to format time range in timezone ${userTimezone}`, error);
        return `${startTime} - ${endTime}`;
    }
}

/**
 * Get business hours display string in user's timezone
 * 
 * @example
 * const hours = getBusinessHours(["Mon", "Tue", "Wed", "Thu", "Fri"], "09:00", "17:00", "America/New_York");
 * // Output: "Mon-Fri 9:00 AM - 5:00 PM EST"
 */
export function getBusinessHours(
    days: string[],
    startTime: string,
    endTime: string,
    userTimezone: string
): string {
    try {
        const daysStr = days.join(", ");
        const timeRange = formatTimeRange(startTime, endTime, userTimezone);
        return `${daysStr} ${timeRange}`;
    } catch (error) {
        console.warn("Failed to format business hours", error);
        return `${days.join(", ")} ${startTime} - ${endTime}`;
    }
}

/**
 * Parse a time string in a specific timezone and return ISO string
 * 
 * @example
 * const iso = parseTimeInTimezone("09:00", "America/New_York", "2024-01-15");
 * // Output: "2024-01-15T14:00:00.000Z"
 */
export function parseTimeInTimezone(
    time: string,
    userTimezone: string,
    dateString?: string
): string {
    try {
        const [hours, minutes] = time.split(":").map(Number);
        const date = dateString ? dayjs(dateString) : dayjs();
        const parsed = date
            .tz(userTimezone)
            .set("hour", hours)
            .set("minute", minutes || 0)
            .set("second", 0)
            .set("millisecond", 0);
        return parsed.toISOString();
    } catch (error) {
        console.warn(`Failed to parse time in timezone ${userTimezone}`, error);
        return dayjs().toISOString();
    }
}
