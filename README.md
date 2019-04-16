# EDD Exporter

A helper plugin for EDD that automatically exports all your store's licenses data into a CSV file compatible with Freemius CSV importer.

## How it works?
After activating the plugin it will automatically start exporting the relevant data in the background to `.../wp-content/uploads/edd-export.csv`.

The plugin is non-blocking, which means that it won't affect your store's performance. It invokes a background HTTP request that will export the first 500 licenses data. If there are more licenses, it will invoke the next background HTTP request to handle the next 500 licenses, and so on.

The export takes about 60 sec per 500 licenses, so give it enough time based on the number of licenses created on your store.