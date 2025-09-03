<?php
/**
 * KC Metro Live - Prompt Builder Module
 * Generates comprehensive research prompts for the AI agent
 */

defined('ABSPATH') || exit;

class KC_ML_Prompt_Builder {
    
    private $kc_center_lat = 39.0997;
    private $kc_center_lng = -94.5786;
    private $search_radius = 50; // miles
    
    public function __construct() {
        // Initialize any needed data
    }
    
    /**
     * Build comprehensive events research prompt
     */
    public function build_events_prompt($limit = 10) {
        $current_date = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime('+90 days'));
        
        $prompt = "COMPREHENSIVE KANSAS CITY EVENTS RESEARCH

MISSION: Find {$limit} NEW upcoming live music events in Kansas City Metro area

SEARCH PARAMETERS:
- Geographic Area: Kansas City, MO and KS within {$this->search_radius} miles of {$this->kc_center_lat}°N, {$this->kc_center_lng}°W
- Date Range: {$current_date} to {$end_date} (up to 90 days ahead)
- Event Types: Live music, karaoke, open mic nights, jam sessions, acoustic performances
- Venue Types: Bars, restaurants, breweries, wineries, distilleries, small music venues
- EXCLUDE: Large concert halls, arenas, stadium shows, Ticketmaster events

MANDATORY SEARCH REQUIREMENTS:
🔍 SEARCH AT LEAST 20 DIFFERENT SOURCES including:

PRIMARY SOURCES (Check these first):
1. Individual venue websites with event calendars
2. Venue Facebook pages and events
3. Venue Instagram posts
4. Performer websites and tour dates
5. Performer social media announcements

SECONDARY SOURCES:
6. https://kclive411.com/ (Kansas City live music directory)
7. https://kansascitymusic.com/ (Local music community site)
8. Kansas City tourism event calendars
9. Local newspaper event listings
10. Chamber of Commerce event calendars
11. Meetup.com music events
12. Eventbrite local music events
13. Facebook Events search for Kansas City
14. Google Events search
15. Yelp event listings
16. Local radio station event listings
17. Music venue aggregator sites
18. Community Facebook groups
19. Local blog event roundups
20. University and college event calendars

SEARCH STRATEGY:
- Start with venue-specific searches (more reliable)
- Cross-reference events across multiple sources
- Verify event details from at least 2 sources when possible
- Look for recurring events (weekly/monthly shows)
- Check for special themed nights or seasonal events

VENUE RESEARCH REQUIREMENTS:
For each venue found, gather:
- Complete address and contact information
- Venue type and capacity
- Atmosphere description
- Parking and accessibility information
- Reviews and sentiment analysis from Google/Yelp
- Social media presence and activity level

PERFORMER RESEARCH REQUIREMENTS:
For each performer found, gather:
- Genre/style of music
- Local vs touring status
- Band member information (public only)
- Social media links and activity
- Performance history and reviews
- Fan sentiment from social media

SENTIMENT ANALYSIS INSTRUCTIONS:
Rate venues and performers using this system:
- Collect reviews from Google, Yelp, Facebook
- Count positive words: amazing, awesome, excellent, fantastic, wonderful, great, love, fun, best, incredible, outstanding, perfect, brilliant, superb
- Count negative words: bad, terrible, awful, horrible, worst, dirty, boring, disappointing, poor, rude, slow, expensive, crowded, loud
- Calculate sentiment ratio
- Base ratings: ugh (<2 stars), meh (2-3 stars), good (4 stars), great (5 stars)
- Adjust: +1 level if positive words outnumber negative 2:1, -1 level if more negative

CONFLICT HANDLING:
When you find conflicting information:
- Note the conflict in the notes array
- Specify which sources provide different information
- Include the date of discovery
- Indicate which source appears more reliable

DATA QUALITY REQUIREMENTS:
- Verify event dates and times from multiple sources
- Confirm venue addresses exist and are correct
- Check that performer names are spelled consistently
- Validate contact information when possible
- Flag any suspicious or unverified information

IMAGE GENERATION GUIDANCE:
For each event, create diverse, engaging images:
- Events: Capture the energy and atmosphere (stage, crowd, lighting)
- Venues: Show the character and style of the space
- Performers: Reflect their music genre and personality
- Use varied artistic styles: photorealistic, artistic, vintage, modern
- No text in images, no copyrighted logos
- Focus on mood and atmosphere over specific details

SPECIAL CONSIDERATIONS:
- Look for holiday/seasonal themed events
- Check for outdoor events that may be weather dependent
- Note any events requiring advance tickets
- Identify recurring series (like weekly open mic nights)
- Pay attention to age restrictions and cover charges
- Look for special collaborations between venues and performers

CURRENT CONTEXT:
Today's date: {$current_date}
Season: " . $this->get_current_season() . "
Local events to consider: " . $this->get_seasonal_considerations() . "

RETURN FORMAT:
Provide exactly {$limit} unique events in the specified JSON format. Ensure each event has:
- Complete venue information with sentiment analysis
- All performer details with genre classification
- Accurate date/time information
- Source citations for verification
- Notes for any conflicts or uncertainties
- Research quality indicators

QUALITY CHECKPOINTS:
✓ Are all {$limit} events genuinely NEW and upcoming?
✓ Do all venues fall within the 50-mile radius?
✓ Are event details verified from multiple sources?
✓ Do sentiment ratings reflect actual review analysis?
✓ Are performer genres and types accurately classified?
✓ Are there comprehensive source citations?
✓ Do venue descriptions capture the actual atmosphere?

START YOUR COMPREHENSIVE SEARCH NOW. Be thorough, accurate, and creative in finding diverse, exciting live music opportunities in Kansas City!";

        return $prompt;
    }
    
    /**
     * Build venue-specific research prompt
     */
    public function build_venue_research_prompt($venue_name, $venue_address = '') {
        $current_date = date('Y-m-d');
        
        $prompt = "COMPREHENSIVE VENUE RESEARCH: {$venue_name}

MISSION: Gather complete, accurate information about this Kansas City area venue

TARGET VENUE:
- Name: {$venue_name}";
        
        if (!empty($venue_address)) {
            $prompt .= "
- Address: {$venue_address}";
        }
        
        $prompt .= "

RESEARCH REQUIREMENTS:

PRIMARY SOURCES (Search these first):
1. Official venue website
2. Venue Facebook page
3. Venue Instagram profile
4. Google Business listing
5. Yelp business page

SECONDARY SOURCES:
6. TripAdvisor reviews
7. Local tourism websites
8. Chamber of Commerce listings
9. Local newspaper mentions
10. Food/drink blog reviews
11. Event listing sites
12. Local directory listings

INFORMATION TO GATHER:

BASIC DETAILS:
- Complete legal name and any nicknames/alternative names
- Full street address, city, state, ZIP
- Phone number and email (business only)
- Website URL and social media links
- Business hours and event schedule

VENUE CHARACTERISTICS:
- Type: bar, restaurant, brewery, winery, distillery, etc.
- Capacity (approximate number of people)
- Indoor/outdoor seating areas
- Stage or performance area details
- Parking situation (free lot, street parking, paid, etc.)
- Accessibility features (wheelchair access, etc.)
- Pet-friendly policy

ATMOSPHERE & DESCRIPTION:
- Interior style and decor
- Target demographic and typical crowd
- Music policy and live event frequency
- Food/drink specialties
- Price range and typical costs
- Special features or unique selling points

SENTIMENT ANALYSIS:
Search reviews from Google, Yelp, Facebook, TripAdvisor and:
- Count positive sentiment words: amazing, awesome, excellent, fantastic, wonderful, great, love, fun, best, incredible, outstanding, perfect, brilliant, superb, cozy, friendly, welcoming, clean, delicious
- Count negative sentiment words: bad, terrible, awful, horrible, worst, dirty, boring, disappointing, poor, rude, slow, expensive, crowded, loud, unfriendly, overpriced, dingy
- Calculate average star rating across platforms
- Note specific praise or complaints about live music events
- Assess overall reputation in the community

LIVE MUSIC FOCUS:
- Frequency of live music events
- Types of performances hosted
- Quality of sound system
- Stage size and setup
- History with local performers
- Reputation among musicians

OPERATIONAL DETAILS:
- Ownership information (if publicly available)
- Years in operation
- Any recent changes or renovations
- Special events or themed nights
- Private event capabilities

CONFLICT RESOLUTION:
If you find conflicting information:
- Note discrepancies between sources
- Indicate which source appears most current/reliable
- Include dates when information was last updated
- Flag items that need verification

CURRENT CONTEXT:
Research date: {$current_date}
Focus on current, active information

Return the venue data in the specified JSON format with complete citations for all information gathered.";

        return $prompt;
    }
    
    /**
     * Build performer-specific research prompt
     */
    public function build_performer_research_prompt($performer_name, $music_style = '') {
        $current_date = date('Y-m-d');
        
        $prompt = "COMPREHENSIVE PERFORMER RESEARCH: {$performer_name}

MISSION: Gather complete, accurate information about this Kansas City area musical act

TARGET PERFORMER:
- Name: {$performer_name}";
        
        if (!empty($music_style)) {
            $prompt .= "
- Suspected Genre: {$music_style}";
        }
        
        $prompt .= "

RESEARCH REQUIREMENTS:

PRIMARY SOURCES (Search these first):
1. Official performer website
2. Facebook artist page
3. Instagram profile
4. Bandcamp or music platform profiles
5. YouTube channel
6. Spotify/Apple Music artist pages

SECONDARY SOURCES:
7. Local music blog features
8. Venue websites mentioning the performer
9. Event listing sites
10. Local newspaper music coverage
11. Radio station mentions
12. Music festival lineups
13. Local music scene forums/groups

INFORMATION TO GATHER:

BASIC DETAILS:
- Official name and any stage names/variations
- Type: solo artist, duo, trio, band, group, orchestra
- Based location (city, state)
- Formation date or years active
- Official website and social media links

MUSICAL INFORMATION:
- Primary genre(s): rock, jazz, blues, country, folk, reggae, pop, indie, electronic, etc.
- Musical style and influences
- Original music vs covers ratio
- Notable songs or albums
- Instrumentation and band setup

MEMBER INFORMATION (Public Only):
- Band member names (if publicly listed)
- Instruments played
- Roles within the group
- Any notable background or experience

PERFORMANCE DETAILS:
- Local vs touring status
- Typical venue types they play
- Performance frequency and schedule
- Stage presence and energy level
- Audience demographic and fan base

REPUTATION & SENTIMENT:
Search for mentions in:
- Music blog reviews
- Fan comments on social media
- Venue testimonials
- Audience feedback
- Local music scene opinions

Count positive sentiment: amazing, talented, incredible, awesome, fantastic, energetic, professional, engaging, skilled, brilliant, captivating, outstanding
Count negative sentiment: boring, terrible, unprofessional, loud, disappointing, amateur, awkward, poor, mediocre

CAREER HIGHLIGHTS:
- Notable performances or venues
- Awards or recognition
- Media coverage or features
- Collaborations with other artists
- Community involvement or charity work

LOCAL CONNECTIONS:
- Relationship with Kansas City music scene
- Regular venues or recurring gigs
- Local musician collaborations
- Community reputation and standing
- Contribution to local music culture

BOOKING & BUSINESS:
- Contact information for booking (if publicly available)
- Management or representation
- Typical performance fees (if mentioned publicly)
- Technical requirements or rider information

RECENT ACTIVITY:
- Latest releases or announcements
- Upcoming shows or tours
- Recent social media activity
- Current projects or collaborations

CONFLICT RESOLUTION:
If you find conflicting information:
- Note discrepancies between sources
- Indicate which source appears most current/reliable
- Include dates when information was last updated
- Prioritize official sources over fan-generated content

CURRENT CONTEXT:
Research date: {$current_date}
Focus on current, active information

Return the performer data in the specified JSON format with complete citations for all information gathered.";

        return $prompt;
    }
    
    /**
     * Build image generation prompt
     */
    public function build_image_prompt($type, $name, $data = array()) {
        $base_style = "Create a vibrant, engaging image. High quality, professional appearance. No text, no copyrighted logos. ";
        
        switch ($type) {
            case 'event':
                $style = $this->get_event_image_style($data);
                $theme = $data['theme'] ?? '';
                $genre = !empty($data['genre']) ? implode(', ', $data['genre']) : 'live music';
                
                return $base_style . "Event image for '{$name}'. Style: {$style}. Theme: {$theme}. Genre: {$genre}. Show the energy and atmosphere of a live music performance. Include stage lighting, audience engagement, and musical instruments. Make it feel exciting and inviting.";
                
            case 'venue':
                $venue_type = $data['type'] ?? 'bar';
                $atmosphere = $this->get_venue_atmosphere($data);
                
                return $base_style . "Venue image for '{$name}', a {$venue_type} in Kansas City. Atmosphere: {$atmosphere}. Show the interior character and welcoming environment. Include seating areas, lighting, and architectural details that make it unique. Make it look inviting for live music events.";
                
            case 'performer':
                $music_style = !empty($data['style_of_music']) ? implode(', ', $data['style_of_music']) : 'general music';
                $performer_type = $data['performer_type'] ?? 'band';
                $artistic_style = $this->get_performer_artistic_style($music_style);
                
                return $base_style . "Performer image for '{$name}', a {$performer_type} playing {$music_style}. Artistic style: {$artistic_style}. Show musical instruments and performance elements that reflect their genre. Create an image that captures their musical personality and energy.";
                
            default:
                return $base_style . "Musical themed image for '{$name}'. Make it vibrant and engaging with musical elements.";
        }
    }
    
    /**
     * Get current season for contextual event searching
     */
    private function get_current_season() {
        $month = date('n');
        
        if (in_array($month, [12, 1, 2])) {
            return 'Winter';
        } elseif (in_array($month, [3, 4, 5])) {
            return 'Spring';
        } elseif (in_array($month, [6, 7, 8])) {
            return 'Summer';
        } else {
            return 'Fall';
        }
    }
    
    /**
     * Get seasonal considerations for event searching
     */
    private function get_seasonal_considerations() {
        $month = date('n');
        $considerations = array();
        
        // Holiday events
        if ($month == 12) {
            $considerations[] = "Holiday parties and New Year events";
        } elseif ($month == 1) {
            $considerations[] = "New Year celebrations and winter indoor events";
        } elseif ($month == 2) {
            $considerations[] = "Valentine's Day events and winter music series";
        } elseif ($month == 3) {
            $considerations[] = "St. Patrick's Day celebrations and spring music festivals";
        } elseif (in_array($month, [4, 5])) {
            $considerations[] = "Spring outdoor events and festival season beginning";
        } elseif (in_array($month, [6, 7, 8])) {
            $considerations[] = "Summer outdoor concerts, patio events, and festival season";
        } elseif ($month == 9) {
            $considerations[] = "Back-to-school events and fall festival season";
        } elseif ($month == 10) {
            $considerations[] = "Halloween events and harvest celebrations";
        } elseif ($month == 11) {
            $considerations[] = "Thanksgiving events and holiday season beginning";
        }
        
        // Weather considerations
        $season = $this->get_current_season();
        if (in_array($season, ['Winter', 'Fall'])) {
            $considerations[] = "Indoor venue preference due to weather";
        } else {
            $considerations[] = "Outdoor venue opportunities and patio events";
        }
        
        return implode(', ', $considerations);
    }
    
    /**
     * Get event image style based on data
     */
    private function get_event_image_style($data) {
        $event_type = $data['event_type'] ?? 'live music';
        $theme = $data['theme'] ?? '';
        
        $styles = array(
            'concert' => 'dramatic stage lighting with silhouettes',
            'festival' => 'colorful and energetic outdoor festival atmosphere',
            'karaoke' => 'fun and casual with microphone focus',
            'open mic' => 'intimate and supportive community atmosphere',
            'jam session' => 'relaxed and collaborative musical setting'
        );
        
        $base_style = $styles[$event_type] ?? $styles['live music'] ?? 'vibrant musical performance';
        
        if (!empty($theme)) {
            $base_style .= ", incorporating {$theme} theme elements";
        }
        
        return $base_style;
    }
    
    /**
     * Get venue atmosphere description for image generation
     */
    private function get_venue_atmosphere($data) {
        $type = $data['type'] ?? 'bar';
        $rating = $data['rating_sentiment'] ?? 'good';
        $outdoor_indoor = $data['outdoor_indoor'] ?? 'indoor';
        
        $atmospheres = array(
            'bar' => 'cozy and social with warm lighting',
            'restaurant' => 'welcoming and comfortable dining atmosphere',
            'brewery' => 'industrial-chic with beer-focused decor',
            'winery' => 'elegant and sophisticated wine country feel',
            'distillery' => 'rustic and artisanal craft spirit atmosphere'
        );
        
        $base_atmosphere = $atmospheres[$type] ?? 'welcoming and musical';
        
        if ($outdoor_indoor == 'outdoor') {
            $base_atmosphere .= ', outdoor seating with natural lighting';
        } elseif ($outdoor_indoor == 'both') {
            $base_atmosphere .= ', mix of indoor and outdoor spaces';
        }
        
        return $base_atmosphere;
    }
    
    /**
     * Get artistic style for performer images
     */
    private function get_performer_artistic_style($music_style) {
        $style_map = array(
            'rock' => 'bold and energetic with dramatic lighting',
            'jazz' => 'sophisticated and moody with warm tones',
            'blues' => 'soulful and atmospheric with deep colors',
            'country' => 'rustic and authentic with natural elements',
            'folk' => 'organic and intimate with acoustic instruments',
            'reggae' => 'colorful and laid-back with tropical vibes',
            'electronic' => 'futuristic and dynamic with neon elements',
            'indie' => 'artistic and alternative with creative composition',
            'pop' => 'bright and polished with contemporary style',
            'classical' => 'elegant and refined with formal presentation'
        );
        
        // Handle multiple styles
        if (strpos($music_style, ',') !== false) {
            $styles = array_map('trim', explode(',', $music_style));
            $primary_style = $styles[0];
        } else {
            $primary_style = $music_style;
        }
        
        return $style_map[strtolower($primary_style)] ?? 'dynamic and musical with performance energy';
    }
    
    /**
     * Build test prompt for API validation
     */
    public function build_test_prompt() {
        return "Test prompt for KC Metro Live. Find 1 upcoming live music event in Kansas City area. Return in JSON format with event, venue, and performer data as specified in the system prompt. This is a connectivity test.";
    }
    
    /**
     * Build monthly update prompt
     */
    public function build_monthly_update_prompt() {
        $current_date = date('Y-m-d');
        
        return "MONTHLY UPDATE SCAN - Kansas City Live Music Scene

MISSION: Review and update existing venue and performer information

SEARCH FOCUS:
- Check all existing venues for updated information
- Verify performer details and current status
- Look for new venues that may have opened
- Update sentiment ratings based on recent reviews
- Refresh contact information and social media links

PRIORITY UPDATES:
1. Venue business hours and contact information
2. New social media accounts or website changes
3. Recent reviews and sentiment analysis
4. Performer lineup changes or new acts
5. Venue renovations or capacity changes

Date: {$current_date}
Type: Monthly maintenance update

Return findings in standard JSON format with updated information and source citations.";
    }
}
?>