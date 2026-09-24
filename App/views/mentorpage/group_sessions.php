<?php

/**
 * group_sessions.php — retired.
 *
 * This was the Group tab of the mentor Sessions page: a list of the mentor's
 * group slots, each with its reserved students and a control to remove one.
 *
 * A group session is a session, so it now appears in Upcoming and Ongoing
 * like any other — one card per slot rather than one per booking — and
 * clicking that card opens the detail panel holding the participants and the
 * Remove control. There is no longer a separate tab for it.
 *
 * The file stays because `mentor-groups` is a route, and links to it exist in
 * the wild (notifications, bookmarks). It sends them to the tab that now
 * holds the same sessions instead of 404ing or serving a bare fragment.
 *
 * The POST handler that removed a student moved to session_request.php, which
 * is the page its form posts to. It is not duplicated here: two copies of a
 * rule that cancels bookings is how the two come to disagree.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (($_SESSION['role'] ?? null) !== 'mentor') {
    header('Location: ' . url('welcomepage'));
    exit;
}

header('Location: ' . url('mentor-requests') . '?tab=upcoming');
exit;
