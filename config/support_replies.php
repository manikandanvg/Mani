<?php

/**
 * Ready replies for the Support Chat desk, and the rules of the auto-reply bot (2026-11-18).
 *
 * Each reply is FIXED TEXT: nothing about the member (name, ID, balance, phone) is ever put
 * into it, so a reply can never leak data. `match` is a pattern looked for in the user's own
 * message to suggest the reply (desk) or to pick the acknowledgement (bot).
 *
 * The bot only ACKNOWLEDGES: it posts one of these texts, once per chat, and changes nothing
 * else. It is OFF unless SUPPORT_BOT_AUTO_REPLY=true is set in .env.
 */
return [

    'bot' => [
        'auto_reply' => (bool) env('SUPPORT_BOT_AUTO_REPLY', false),
        'name' => 'Auto reply',
        // used when no topic matches
        'fallback' => 'general',
        // Follow-ups: the bot may answer a chat at most this many times in all, so a user can
        // never end up in a loop with it. After that only staff reply.
        'max_replies' => 3,
        // a follow-up that is only a thank-you / "ok" (no new question in it)
        'thanks' => '^(\W|ok|okay|okey|thanks?|thank\s*(you|u)|tq|ty|sir|madam|mam|team|fine|noted|super|good|great|welcome|நன்றி|சரி|ஓகே)+$',
    ],

    /*
     * Tamil versions of the same replies (2026-11-18), keyed like `templates`. Used by the bot when
     * the user wrote in Tamil script, and offered to the desk as "… — தமிழ்". A reply with no Tamil
     * version falls back to English. Same rule: fixed text, nothing about the member.
     */
    'tamil' => [
        'wallet' => "அன்புள்ள விநியோகஸ்தரே,\n\nஎங்களைத் தொடர்பு கொண்டதற்கு நன்றி. உங்கள் வாலட் / டிஜி கோல்டு இருப்பு குறித்த கோரிக்கை பதிவு செய்யப்பட்டுள்ளது.\n\nஎங்கள் கணக்குப் பிரிவு உங்கள் இருப்பைப் பதிவுகளுடன் சரிபார்த்து வருகிறது. சரிபார்ப்பு முடிந்ததும் இதே உரையாடலில் தெரிவிப்போம்.\n\nவிரைவாகச் சரிபார்க்க, தயவுசெய்து கீழ்க்கண்டவற்றைத் தெரிவிக்கவும்:\n• நீங்கள் எதிர்பார்க்கும் தொகை\n• அது எந்த மாதம் அல்லது தேதிக்கு உரியது\n\nஉங்கள் கடவுச்சொல் அல்லது OTP-ஐ யாரிடமும் பகிர வேண்டாம்.\n\nலார்ட்ஸ் உதவி மையம்",

        'withdrawal' => "அன்புள்ள விநியோகஸ்தரே,\n\nஎங்களைத் தொடர்பு கொண்டதற்கு நன்றி. உங்கள் பணம் எடுத்தல் (Withdrawal) குறித்த கோரிக்கை பதிவு செய்யப்பட்டுள்ளது.\n\nதலைமை அலுவலக ஒப்புதலுக்குப் பிறகு, உங்கள் கிளை மூலம் பணம் வழங்கப்படும். உங்கள் கோரிக்கையின் நிலையைச் சரிபார்த்து இதே உரையாடலில் தெரிவிப்போம்.\n\nகோரிக்கை செய்த தேதி மற்றும் தொகையை இதுவரை குறிப்பிடவில்லை என்றால், தயவுசெய்து தெரிவிக்கவும்.\n\nலார்ட்ஸ் உதவி மையம்",

        'incentive' => "அன்புள்ள விநியோகஸ்தரே,\n\nஎங்களைத் தொடர்பு கொண்டதற்கு நன்றி. உங்கள் ஊக்கத்தொகை குறித்த கோரிக்கை பதிவு செய்யப்பட்டுள்ளது.\n\nதலைமை அலுவலக ஒப்புதலுக்குப் பிறகே ஊக்கத்தொகை வாலட்டில் வரவு வைக்கப்படும். ஒரு நாளில் பெறக்கூடிய வருமானம், My Status பகுதியில் காட்டப்படும் உங்கள் Earning Potential (EP) அளவிற்குள் இருக்கும். உங்கள் கோரிக்கையைச் சரிபார்த்து இதே உரையாடலில் தெரிவிப்போம்.\n\nஎந்த மாதம், முடிந்தால் எந்த பில் அல்லது எந்த விநியோகஸ்தர் தொடர்பானது என்பதைத் தெரிவிக்கவும்.\n\nலார்ட்ஸ் உதவி மையம்",

        'gold_saving' => "அன்புள்ள விநியோகஸ்தரே,\n\nஎங்களைத் தொடர்பு கொண்டதற்கு நன்றி. உங்கள் தங்க சேமிப்புத் திட்டம் குறித்த கோரிக்கை பதிவு செய்யப்பட்டுள்ளது.\n\nஉங்கள் தவணைகளை வசூல் பதிவுகளுடன் சரிபார்த்து இதே உரையாடலில் தெரிவிப்போம்.\n\nதவணை செலுத்திய தேதி மற்றும் கிளையைத் தயவுசெய்து தெரிவிக்கவும்.\n\nலார்ட்ஸ் உதவி மையம்",

        'redeem' => "அன்புள்ள விநியோகஸ்தரே,\n\nஎங்களைத் தொடர்பு கொண்டதற்கு நன்றி. உங்கள் QR / ரிடீம் குறித்த கோரிக்கை பதிவு செய்யப்பட்டுள்ளது.\n\nQR-ஐ, கிளையில் இருப்பில் உள்ள தங்கம் அல்லது வெள்ளிப் பொருட்களுக்கு ரிடீம் செய்யலாம். உங்கள் கோரிக்கையைச் சரிபார்த்து இதே உரையாடலில் தெரிவிப்போம்.\n\nபில் எண் மற்றும் நீங்கள் சென்ற கிளையைத் தயவுசெய்து தெரிவிக்கவும்.\n\nலார்ட்ஸ் உதவி மையம்",

        'documents' => "அன்புள்ள விநியோகஸ்தரே,\n\nஎங்களைத் தொடர்பு கொண்டதற்கு நன்றி. உங்கள் ஒப்பந்தம் / இன்வாய்ஸ் குறித்த கோரிக்கை பதிவு செய்யப்பட்டுள்ளது.\n\nஉங்கள் ஒப்பந்தங்கள் மற்றும் வரி இன்வாய்ஸ்கள் செயலியில் Documents பகுதியில் உள்ளன. ஏதேனும் ஆவணம் இல்லை என்றாலோ, விவரம் தவறாக இருந்தாலோ, பில் எண்ணையும் திருத்த வேண்டிய விவரத்தையும் தெரிவிக்கவும்; நாங்கள் சரிபார்க்கிறோம்.\n\nலார்ட்ஸ் உதவி மையம்",

        'dealer' => "அன்புள்ள டீலரே,\n\nஎங்களைத் தொடர்பு கொண்டதற்கு நன்றி. உங்கள் ஸ்டாக் / ஆர்டர் குறித்த கோரிக்கை பதிவு செய்யப்பட்டுள்ளது.\n\nதலைமை அலுவலக ஒப்புதலுக்குப் பிறகு ஆர்டர்கள் வழங்கப்படும். உங்கள் கோரிக்கையைச் சரிபார்த்து இதே உரையாடலில் தெரிவிப்போம்.\n\nஆர்டர் எண் மற்றும் ஆர்டர் செய்த தேதியைத் தயவுசெய்து தெரிவிக்கவும்.\n\nலார்ட்ஸ் உதவி மையம்",

        'status' => "அன்புள்ள விநியோகஸ்தரே,\n\nஎங்களைத் தொடர்பு கொண்டதற்கு நன்றி. உங்கள் வணிக மதிப்பு / நிலை குறித்த கோரிக்கை பதிவு செய்யப்பட்டுள்ளது.\n\nஉங்கள் வணிக மதிப்பு, குழு வணிக மதிப்பு மற்றும் நிலை ஆகியவை செயலியில் My Status பகுதியில் காட்டப்படும்; அவை ஒவ்வொரு இரவும் புதுப்பிக்கப்படும். உங்கள் கோரிக்கையைச் சரிபார்த்து இதே உரையாடலில் தெரிவிப்போம்.\n\nலார்ட்ஸ் உதவி மையம்",

        'profile' => "அன்புள்ள விநியோகஸ்தரே,\n\nஎங்களைத் தொடர்பு கொண்டதற்கு நன்றி. உங்கள் சுயவிவர மாற்றம் குறித்த கோரிக்கை பதிவு செய்யப்பட்டுள்ளது.\n\nஉங்கள் பாதுகாப்பிற்காக, பெயர், மொபைல் எண் அல்லது வங்கி விவர மாற்றம், உங்கள் கிளையில் அடையாளச் சான்றுடன் சரிபார்த்த பிறகே செய்யப்படும். தயவுசெய்து உங்கள் கிளைக்குச் செல்லவும், அல்லது எந்த விவரம் மாற வேண்டும் என்பதை இங்கே தெரிவிக்கவும் (அடையாள எண்களை இந்த உரையாடலில் அனுப்ப வேண்டாம்).\n\nலார்ட்ஸ் உதவி மையம்",

        'team' => "அன்புள்ள விநியோகஸ்தரே,\n\nஎங்களைத் தொடர்பு கொண்டதற்கு நன்றி. உங்கள் குழு / இணைப்பு குறித்த கோரிக்கை பதிவு செய்யப்பட்டுள்ளது.\n\nசேர்க்கைப் பதிவுகளுடன் சரிபார்த்து இதே உரையாடலில் தெரிவிப்போம்.\n\nதொடர்புடைய விநியோகஸ்தர் ஐடி மற்றும் சேர்ந்த தேதியைத் தயவுசெய்து தெரிவிக்கவும்.\n\nலார்ட்ஸ் உதவி மையம்",

        'login' => "அன்புள்ள விநியோகஸ்தரே,\n\nஎங்களைத் தொடர்பு கொண்டதற்கு நன்றி. உள்நுழைவதில் உங்களுக்குச் சிரமம் இருப்பதைப் பதிவு செய்துள்ளோம்.\n\nபதிவு செய்த மொபைல் எண்ணிலிருந்து வரும் மிஸ்டு கால் மூலம் செயலி உங்கள் எண்ணைச் சரிபார்க்கிறது. பதிவு செய்த எண்ணின் சிம் அதே போனில் இருப்பதை உறுதி செய்து மீண்டும் முயற்சிக்கவும். அப்போதும் முடியவில்லை என்றால், திரையில் காட்டப்படும் செய்தியைத் தெரிவிக்கவும்.\n\nஉங்கள் கடவுச்சொல்லை யாரிடமும் பகிர வேண்டாம்.\n\nலார்ட்ஸ் உதவி மையம்",

        'app' => "அன்புள்ள விநியோகஸ்தரே,\n\nஎங்களைத் தொடர்பு கொண்டதற்கு நன்றி. ஏற்பட்ட சிரமத்திற்கு வருந்துகிறோம்.\n\nதயவுசெய்து செயலியைப் புதிய பதிப்பிற்கு அப்டேட் செய்து மீண்டும் முயற்சிக்கவும். பிரச்சனை தொடர்ந்தால், எந்தத் திரையில் ஏற்படுகிறது என்பதையும் காட்டப்படும் செய்தியையும் தெரிவிக்கவும்; எங்கள் குழு சரிபார்க்கும்.\n\nலார்ட்ஸ் உதவி மையம்",

        'general' => "அன்புள்ள விநியோகஸ்தரே,\n\nஎங்களைத் தொடர்பு கொண்டதற்கு நன்றி. உங்கள் செய்தி கிடைத்தது; எங்கள் குழு அதைச் சரிபார்த்து வருகிறது. இதே உரையாடலில் உங்களுக்குத் தெரிவிப்போம்.\n\nஉங்கள் கடவுச்சொல் அல்லது OTP-ஐ யாரிடமும் பகிர வேண்டாம்.\n\nலார்ட்ஸ் உதவி மையம்",

        'received' => "நன்றி. உங்கள் விவரங்கள் கிடைத்தன; அவை உங்கள் கோரிக்கையுடன் சேர்க்கப்பட்டுள்ளன. எங்கள் குழு சரிபார்த்து இதே உரையாடலில் தெரிவிக்கும்.\n\nலார்ட்ஸ் உதவி மையம்",

        'thanks' => "நன்றி. இதே உரையாடலில் உங்களுக்குத் தெரிவிப்போம். எப்போது வேண்டுமானாலும் இங்கே எங்களுக்கு எழுதலாம்.\n\nலார்ட்ஸ் உதவி மையம்",

        'need_details' => "அன்புள்ள விநியோகஸ்தரே,\n\nஇதைச் சரிபார்க்க எங்களுக்குக் கூடுதல் விவரங்கள் தேவை. தயவுசெய்து கீழ்க்கண்டவற்றைத் தெரிவிக்கவும்:\n• பில் எண் அல்லது தேதி\n• தொகை\n• தொடர்புடைய கிளை\n\nலார்ட்ஸ் உதவி மையம்",

        'under_review' => "அன்புள்ள விநியோகஸ்தரே,\n\nஉங்கள் கோரிக்கை எங்கள் கணக்குப் பிரிவின் சரிபார்ப்பில் உள்ளது. அது முடிந்தவுடன் இதே உரையாடலில் தெரிவிப்போம். உங்கள் பொறுமைக்கு நன்றி.\n\nலார்ட்ஸ் உதவி மையம்",

        'resolved' => "அன்புள்ள விநியோகஸ்தரே,\n\nஉங்கள் கோரிக்கை சரிபார்க்கப்பட்டு தீர்வு காணப்பட்டுள்ளது. தயவுசெய்து செயலியில் பார்க்கவும். இந்த உரையாடலை முடிக்கிறோம்; எப்போது வேண்டுமானாலும் மீண்டும் எங்களுக்கு எழுதலாம்.\n\nநன்றி,\nலார்ட்ஸ் உதவி மையம்",

        'no_change' => "அன்புள்ள விநியோகஸ்தரே,\n\nஉங்கள் கோரிக்கையைப் பதிவுகளுடன் சரிபார்த்தோம். செயலியில் காட்டப்படும் தொகை, திட்டத்தின்படி சரியானதே. இதற்கு மாறாகக் காட்டும் பில் அல்லது ரசீது உங்களிடம் இருந்தால், அதன் எண் மற்றும் தேதியைத் தெரிவிக்கவும்; மீண்டும் சரிபார்க்கிறோம்.\n\nலார்ட்ஸ் உதவி மையம்",
    ],

    // Which topic wins when a message fits several: the specific ones first, the broad
    // "wallet / amount missing" near the end.
    'priority' => ['withdrawal', 'incentive', 'gold_saving', 'redeem', 'documents', 'status', 'profile', 'team', 'login', 'dealer', 'wallet', 'app'],

    'templates' => [

        'wallet' => [
            'title' => 'Wallet / Digi Gold balance or amount missing',
            'match' => 'wallet|digi|balance|missing|migrat|not credit|credited|not received|amount|வாலட்|பணம்|தொகை|வரவில்லை',
            'bot' => true,
            'body' => "Dear Distributor,\n\nThank you for writing to us. We have noted your query about your wallet / Digi Gold balance.\n\nOur accounts team is checking your balance against the records, and we will update you in this chat once it is verified.\n\nTo help us check faster, please reply with:\n• the amount you expect, and\n• the month or date it relates to.\n\nPlease do not share your password or any OTP with anyone.\n\nLord's Support Desk",
        ],

        'withdrawal' => [
            'title' => 'Withdrawal / payout request',
            'match' => 'withdraw|payout|\bbank\b|\bupi\b',
            'bot' => true,
            'body' => "Dear Distributor,\n\nThank you for writing to us. We have noted your query about your withdrawal.\n\nWithdrawals are paid out through your branch after Head Office approval. We are checking the status of your request and will update you in this chat.\n\nPlease reply with the date and the amount of the request if you have not mentioned them.\n\nLord's Support Desk",
        ],

        'incentive' => [
            'title' => 'Incentive / commission / CBC / billing margin',
            'match' => 'incentive|commission|\bic\b|cbc|coupon|margin|income|salary|கமிஷன்|ஊக்கத்தொகை',
            'bot' => true,
            'body' => "Dear Distributor,\n\nThank you for writing to us. We have noted your query about your incentive.\n\nIncentives are credited to the wallet only after Head Office approval, and the earning for a day is limited by your Earning Potential (EP) shown under My Status. We are checking your case and will update you in this chat.\n\nPlease reply with the month and, if possible, the bill or distributor it relates to.\n\nLord's Support Desk",
        ],

        'gold_saving' => [
            'title' => 'Gold Saving renewal / instalment',
            'match' => 'renew|instal|saving|monthly|\bdue\b|சேமிப்பு|தவணை',
            'bot' => true,
            'body' => "Dear Distributor,\n\nThank you for writing to us. We have noted your query about your Gold Saving plan.\n\nWe are checking your instalments against the collection records and will update you in this chat.\n\nPlease reply with the date and the branch where the instalment was paid.\n\nLord's Support Desk",
        ],

        'redeem' => [
            'title' => 'QR / redemption / gold or silver delivery',
            'match' => '\bqr\b|redeem|redemption|coin|deliver|தங்கம்|காயின்',
            'bot' => true,
            'body' => "Dear Distributor,\n\nThank you for writing to us. We have noted your query about your QR / redemption.\n\nA QR is redeemed for gold or silver items at the branch, against the stock held there. We are checking your case and will update you in this chat.\n\nPlease reply with the bill number and the branch you visited.\n\nLord's Support Desk",
        ],

        'documents' => [
            'title' => 'Contract / invoice / bill copy',
            'match' => 'contract|invoice|\bbill\b|pdf|receipt|ஒப்பந்த|பில்|ரசீது',
            'bot' => true,
            'body' => "Dear Distributor,\n\nThank you for writing to us. We have noted your query about your contract / invoice.\n\nYour contracts and tax invoices are available in the app under Documents. If a document is missing or shows a wrong detail, please reply with the bill number and what needs correction, and we will check it.\n\nLord's Support Desk",
        ],

        'dealer' => [
            'title' => 'Dealer: stock / order / branch',
            'match' => '\bstock|\border|dealership|supply|ஸ்டாக்|ஆர்டர்',
            'bot' => true,
            'body' => "Dear Dealer,\n\nThank you for writing to us. We have noted your query about your stock / order.\n\nOrders are supplied after Head Office approval. We are checking your case and will update you in this chat.\n\nPlease reply with the order number and the date it was raised.\n\nLord's Support Desk",
        ],

        'status' => [
            'title' => 'BV / rank / promotion / EP',
            'match' => '\bbv\b|gbv|rank|promotion|\bep\b|earning potential|stage|business value',
            'bot' => true,
            'body' => "Dear Distributor,\n\nThank you for writing to us. We have noted your query about your business value / stage.\n\nYour business value, group business value and stage are shown in the app under My Status, and are refreshed every night. We are checking your case and will update you in this chat.\n\nLord's Support Desk",
        ],

        'profile' => [
            'title' => 'Profile / KYC / mobile or name change',
            'match' => 'kyc|aadhaa?r|\bpan\b|profile|nominee|name change|mobile number|phone number|address|change my|update my|மாற்ற',
            'bot' => true,
            'body' => "Dear Distributor,\n\nThank you for writing to us. We have noted your request about your profile details.\n\nFor your safety, a change of name, mobile number or bank details is done only after verification at your branch with your ID proof. Please visit your branch, or reply here with which detail needs to change (do not send ID numbers in this chat).\n\nLord's Support Desk",
        ],

        'team' => [
            'title' => 'Referral / placement / downline',
            'match' => 'referr|sponsor|upline|downline|placement|genealogy|team',
            'bot' => true,
            'body' => "Dear Distributor,\n\nThank you for writing to us. We have noted your query about your team / placement.\n\nWe are checking it against the joining records and will update you in this chat.\n\nPlease reply with the Distributor ID concerned and the date of joining.\n\nLord's Support Desk",
        ],

        'login' => [
            'title' => 'Login / missed-call verification',
            'match' => 'login|log in|password|otp|missed call|verif|sign in|கடவுச்சொல்|லாகின்',
            'bot' => true,
            'body' => "Dear Distributor,\n\nThank you for writing to us. We have noted that you have trouble signing in.\n\nThe app verifies your number by a missed call from your registered mobile. Please make sure the SIM of your registered number is in the phone and try again. If it still fails, reply with the message shown on the screen.\n\nPlease do not share your password with anyone.\n\nLord's Support Desk",
        ],

        'app' => [
            'title' => 'App error / not working',
            'match' => 'error|not working|not open|crash|loading|server|software|showing|issue|problem|பிரச்சனை|பிழை',
            'bot' => true,
            'body' => "Dear Distributor,\n\nThank you for writing to us. We are sorry for the trouble.\n\nPlease update the app to the latest version from the store and try again. If the problem continues, reply with the screen where it happens and the message shown, and our team will check it.\n\nLord's Support Desk",
        ],

        'general' => [
            'title' => 'General acknowledgement',
            'match' => null,
            'bot' => true,
            'body' => "Dear Distributor,\n\nThank you for writing to us. We have received your message and our team is checking it. We will update you in this chat.\n\nPlease do not share your password or any OTP with anyone.\n\nLord's Support Desk",
        ],

        // ---- follow-ups (sent by the bot when the user answers it; also usable by the desk) ----

        'received' => [
            'title' => 'Follow-up: details received',
            'match' => null,
            'bot' => true,
            'body' => "Thank you. We have received your details and added them to your case. Our team will check and update you in this chat.\n\nLord's Support Desk",
        ],

        'thanks' => [
            'title' => 'Follow-up: you are welcome',
            'match' => null,
            'bot' => true,
            'body' => "You are welcome. We will update you in this chat. You can write to us here at any time.\n\nLord's Support Desk",
        ],

        // ---- desk only (never sent by the bot) ----

        'need_details' => [
            'title' => 'Desk: need more details',
            'match' => null,
            'bot' => false,
            'body' => "Dear Distributor,\n\nTo check this we need a little more information. Please reply with:\n• the bill number or date,\n• the amount, and\n• the branch concerned.\n\nLord's Support Desk",
        ],

        'under_review' => [
            'title' => 'Desk: under review with accounts',
            'match' => null,
            'bot' => false,
            'body' => "Dear Distributor,\n\nYour case is with our accounts team for verification. We will update you in this chat as soon as it is completed. Thank you for your patience.\n\nLord's Support Desk",
        ],

        'resolved' => [
            'title' => 'Desk: resolved, closing the chat',
            'match' => null,
            'bot' => false,
            'body' => "Dear Distributor,\n\nYour query has been checked and resolved. Please check the app. We are closing this chat; you can write to us again at any time.\n\nThank you,\nLord's Support Desk",
        ],

        'no_change' => [
            'title' => 'Desk: checked, figures are correct',
            'match' => null,
            'bot' => false,
            'body' => "Dear Distributor,\n\nWe have checked your case against the records, and the figure shown in the app is correct as per the scheme. If you have a bill or receipt that shows otherwise, please reply with its number and date and we will check again.\n\nLord's Support Desk",
        ],
    ],
];
