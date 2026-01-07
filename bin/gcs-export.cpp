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
    // Load FPP settings (required)
    // ---------------------------------------------------------------------
    LoadSettings("/home/fpp/media", false);

    // ---------------------------------------------------------------------
    // Latitude / Longitude / Timezone come from SETTINGS
    // ---------------------------------------------------------------------
    std::string latStr = getSetting("Latitude");
    std::string lonStr = getSetting("Longitude");
    std::string tz     = getSetting("TimeZone");

    double lat = latStr.empty() ? 0.0 : atof(latStr.c_str());
    double lon = lonStr.empty() ? 0.0 : atof(lonStr.c_str());

    root["latitude"]  = lat;
    root["longitude"] = lon;
    root["timezone"]  = tz;

    // ---------------------------------------------------------------------
    // Locale (holidays, locale name, etc.)
    // ---------------------------------------------------------------------
    Json::Value locale = LocaleHolder::GetLocale();
    root["rawLocale"] = locale;

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------
    bool ok = true;
    std::string error;

    if (lat == 0.0 || lon == 0.0) {
        ok = false;
        error = "Latitude/Longitude not present (or zero) in FPP settings.";
        root["error"] = error;
        std::cerr << "WARN: " << error << std::endl;
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