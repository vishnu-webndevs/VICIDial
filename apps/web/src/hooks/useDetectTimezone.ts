/**
 * React hook for automatic timezone detection
 * Provides user's detected timezone and related utilities
 */

import { useEffect, useState } from "react";
import {
    detectUserTimezone,
    getTimezoneAbbreviation,
    getTimezoneInfo,
    getTimezoneName,
    isValidTimezone,
} from "../lib/timezone-utils";

export interface TimezoneInfo {
    timezone: string;
    abbreviation: string;
    name: string;
    isDaylightSaving: boolean;
}

interface UseDetectTimezoneReturn {
    detectedTimezone: string;
    isLoading: boolean;
    abbreviation: string;
    name: string;
    isDaylightSaving: boolean;
    isValid: boolean;
    error: Error | null;
}

/**
 * Hook to detect user's timezone on mount
 * Useful for pre-populating timezone settings with the user's actual timezone
 *
 * @example
 * const { detectedTimezone } = useDetectTimezone();
 * console.log(detectedTimezone); // "America/New_York"
 */
export function useDetectTimezone(): UseDetectTimezoneReturn {
    const [detectedTimezone, setDetectedTimezone] = useState("UTC");
    const [abbreviation, setAbbreviation] = useState("UTC");
    const [name, setName] = useState("Coordinated Universal Time");
    const [isDaylightSaving, setIsDaylightSaving] = useState(false);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<Error | null>(null);

    useEffect(() => {
        try {
            // Only run on client side
            if (typeof window === "undefined") {
                setIsLoading(false);
                return;
            }

            const info = getTimezoneInfo();
            setDetectedTimezone(info.timezone);
            setAbbreviation(info.abbreviation);
            setName(info.name);
            setIsDaylightSaving(info.isDaylightSaving);
            setError(null);
        } catch (err) {
            setError(err instanceof Error ? err : new Error("Failed to detect timezone"));
            setDetectedTimezone("UTC");
        } finally {
            setIsLoading(false);
        }
    }, []);

    const isValid = isValidTimezone(detectedTimezone);

    return {
        detectedTimezone,
        isLoading,
        abbreviation,
        name,
        isDaylightSaving,
        isValid,
        error,
    };
}

/**
 * Hook to sync detected timezone with a state value
 * Automatically sets initial timezone to user's detected timezone
 *
 * @param onTimezoneChange Optional callback when timezone changes
 *
 * @example
 * const [timezone, setTimezone, detectedTz] = useTimezoneSync();
 * // timezone will be pre-populated with detected timezone
 */
export function useTimezoneSync(
    onTimezoneChange?: (timezone: string) => void
): [string, (tz: string) => void, string] {
    const [timezone, setTimezone] = useState("UTC");
    const { detectedTimezone } = useDetectTimezone();

    // Set timezone to detected value on first load
    useEffect(() => {
        if (detectedTimezone && detectedTimezone !== "UTC") {
            setTimezone(detectedTimezone);
            onTimezoneChange?.(detectedTimezone);
        }
    }, [detectedTimezone, onTimezoneChange]);

    const handleTimezoneChange = (newTimezone: string) => {
        setTimezone(newTimezone);
        onTimezoneChange?.(newTimezone);
    };

    return [timezone, handleTimezoneChange, detectedTimezone];
}

/**
 * Hook to detect if user has DST (Daylight Saving Time) active
 * Useful for understanding if the offset might change seasonally
 */
export function useDaylightSavingTime(): boolean {
    const [isDST, setIsDST] = useState(false);

    useEffect(() => {
        try {
            if (typeof window === "undefined") return;

            // Compare offset between January and July to detect DST
            const january = new Date(2024, 0, 1); // January
            const july = new Date(2024, 6, 1); // July

            const janOffset = new Intl.DateTimeFormat("en-US", {
                timeZoneName: "short",
            }).formatToParts(january);

            const julyOffset = new Intl.DateTimeFormat("en-US", {
                timeZoneName: "short",
            }).formatToParts(july);

            // If abbreviations differ, likely DST zone
            const janAbbr = janOffset.find((p) => p.type === "timeZoneName")?.value;
            const julyAbbr = julyOffset.find((p) => p.type === "timeZoneName")?.value;

            setIsDST(janAbbr !== julyAbbr);
        } catch (error) {
            console.warn("Failed to detect DST", error);
        }
    }, []);

    return isDST;
}
