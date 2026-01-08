#include <iostream>
#include <fstream>
#include <string>
#include <cstdlib>

#include <jsoncpp/json/json.h>

#include "settings.h"
#include "FPPLocale.h"

static const char* OUTPUT_PATH =
    "/home/fpp/media/plugins/GoogleCalendarScheduler/runtime/fpp-env.json";

int main() {
    Json::Value root;
    root["schemaVersion"] = 1;
    root["source"] = "gcs-export";

    // ---------------------------------------------------------------------
    // Load FPP settings (REQUIRED)
    // ---------------------------------------------------------------------
    LoadSettings("/home/fpp/media", false);

    // ---------------------------------------------------------------------
    // Timezone (from settings)
    // ---------------------------------------------------------------------
    std::string timezone = getSetting("TimeZone");
    root["timezone"] = timezone;

    // ---------------------------------------------------------------------
    // Locale + geographic context (from LocaleHolder)
    // ---------------------------------------------------------------------
    double latitude  = LocaleHolder::GetLatitude();
    double longitude = LocaleHolder::GetLongitude();

    root["latitude"]  = latitude;
    root["longitude"] = longitude;

    // Locale region selection (Global / USA / Canada)
    std::string localeRegion = getSetting("Locale");
    root["locale"]["region"] = localeRegion;

    // Full locale payload (holidays, rules, etc.)
    Json::Value locale = LocaleHolder::GetLocale();
    root["locale"]["holidays"] = locale["holidays"];

    // ---------------------------------------------------------------------
    // Validation (ALL required in FPP)
    // ---------------------------------------------------------------------
    bool ok = true;

    if (timezone.empty()) {
        ok = false;
        root["error"] = "Timezone not present in FPP settings.";
        std::cerr << "WARN: Timezone not present in FPP settings." << std::endl;
    }

    if (latitude == 0.0 || longitude == 0.0) {
        ok = false;
        root["error"] =
            "Latitude/Longitude not present (or zero) in FPP locale.";
        std::cerr << "WARN: Latitude/Longitude not present (or zero) in FPP locale." << std::endl;
    }

    if (localeRegion.empty()) {
        ok = false;
        root["error"] =
            "Locale region (Global/USA/Canada) not present in FPP settings.";
        std::cerr << "WARN: Locale region not present in FPP settings." << std::endl;
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