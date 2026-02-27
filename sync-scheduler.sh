#!/bin/bash

# Sync scheduler script - runs Laravel scheduler exactly at XX:XX:00 with self-correction
cd /app

while true; do
    # Get current seconds
    current_seconds=$(date +%S)

    # Calculate seconds to wait until next minute starts
    seconds_to_wait=$((60 - current_seconds))

    # Sleep until the start of the next minute
    if [ $seconds_to_wait -lt 60 ]; then
        sleep $seconds_to_wait
    fi

    # Record start time
    start_time=$(date +%s)

    # Run Laravel scheduler at exactly XX:XX:00
    php artisan schedule:run

    # Calculate execution time
    end_time=$(date +%s)
    execution_time=$((end_time - start_time))

    # Calculate remaining time to complete 60-second cycle
    sleep_time=$((60 - execution_time))

    # Sleep for remaining time to maintain exact 60-second rhythm
    if [ $sleep_time -gt 0 ]; then
        sleep $sleep_time
    fi
done
