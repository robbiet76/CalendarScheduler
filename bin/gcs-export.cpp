#include <iostream>
#include <fstream>
#include <string>
#include <cstdlib>

#include <jsoncpp/json/json.h>
#include "FPPLocale.h"

/*
 * gcs-export
 *
 * Purpose:
 * - Export FPP runtime environment required by GoogleCalendarScheduler
 * - MUST be side-effect free
 * - MUST NOT initialize full FPP runtime
 *
 * This binary intentionally does NOT call LoadSettings().
 */

static const char* SETTINGS_PATH =
    "/home/fpp/media/settings";

static const char* OUTPUT_PATH =
    "/home/fpp/media/plugins/GoogleCalendarScheduler/runtime/fpp-env.json";

/**
 * Read a single value from FPP settings JSON.
 */
static std::string readSetting(const Json::Value& settings,
                               const std::string& key)
{
    if (!settings.isObject()) return "";
    if (!settings.isMember(key)) return "";
    if (!settings[key].isString()) return "";
    return settings[key].asString();
}

int main()
{
    Json::Value root;
    root["schemaVersion"] = 1;
    root["source"] = "gcs-export";

    // ---------------------------------------------------------------------
    // Load settings JSON directly (NO LoadSettings)
    // ---------------------------------------------------------------------
    Json::Value settings;
    {
        std::ifstream in(SETTINGS_PATH);
        if (!in) {
            root["ok"] = false;
            root["error"] = "Unable to open FPP settings file.";
            std::cerr << "ERROR: Unable to open " << SETTINGS_PATH << std::endl;
        } else {
            in >> settings;
        }
    }

    // ---------------------------------------------------------------------
    // Extract canonical values
    // ---------------------------------------------------------------------
    std::string latStr = readSetting(settings, "Latitude");
    std::string lonStr = readSetting(settings, "Longitude");
    std::string tz     = readSetting(settings, "TimeZone");

    double lat = latStr.empty() ? 0.0 : atof(latStr.c_str());
    double lon = lonStr.empty() ? 0.0 : atof(lonStr.c_str());

    root["latitude"]  = lat;
    root["longitude"] = lon;
    root["timezone"]  = tz;

    // ---------------------------------------------------------------------
    // Locale data (holidays, locale name, etc.)
    // ---------------------------------------------------------------------
    Json::Value locale = LocaleHolder::GetLocale();
    root["rawLocale"] = locale;

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------
    bool ok = true;

    if (lat == 0.0 || lon == 0.0) {
        ok = false;
        root["error"] =
            "Latitude/Longitude not present (or zero) in FPP settings.";
        std::cerr
            << "WARN: Latitude/Longitude not present (or zero) in FPP settings."
            << std::endl;
    }

    if (tz.empty()) {
        ok = false;
        root["error"] =
            "Timezone not present in FPP settings.";
        std::cerr
            << "WARN: Timezone not present in FPP settings."
            << std::endl;
    }

    root["ok"] = ok;

    // ---------------------------------------------------------------------
    // Write output
    // ---------------------------------------------------------------------
    std::ofstream out(OUTPUT_PATH);
    if (!out) {
        std::cerr << "ERROR: Unable to write " << OUTPUT_PATH << std::endl;
        return 2;
    }

    out << root.toStyledString();
    out.close();

    return ok ? 0 : 1;
}