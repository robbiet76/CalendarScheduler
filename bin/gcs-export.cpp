#include <iostream>
#include <fstream>
#include <string>

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
    // Load FPP settings
    // Required for timezone and proper FPP initialization
    // ---------------------------------------------------------------------
    LoadSettings("/home/fpp/media", false);

    // ---------------------------------------------------------------------
    // Timezone (from FPP settings)
    // ---------------------------------------------------------------------
    std::string tz = getSetting("timezone");
    root["timezone"] = tz;

    // ---------------------------------------------------------------------
    // Latitude / Longitude (from FPP locale)
    // ---------------------------------------------------------------------
    double lat = 0.0;
    double lon = 0.0;

    Json::Value locale = LocaleHolder::GetLocale();
    root["rawLocale"] = locale;

    if (locale.isObject()) {
        if (locale.isMember("Latitude") && locale["Latitude"].isNumeric()) {
            lat = locale["Latitude"].asDouble();
        }
        if (locale.isMember("Longitude") && locale["Longitude"].isNumeric()) {
            lon = locale["Longitude"].asDouble();
        }
    }

    root["latitude"]  = lat;
    root["longitude"] = lon;

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------
    bool ok = true;

    if (lat == 0.0 || lon == 0.0) {
        ok = false;
        std::string error =
            "Latitude/Longitude not present (or zero) in FPP locale.";
        root["error"] = error;
        std::cerr << "WARN: " << error << std::endl;
    }

    root["ok"] = ok;

    // ---------------------------------------------------------------------
    // Write output JSON
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