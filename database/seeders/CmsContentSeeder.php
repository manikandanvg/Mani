<?php

namespace Database\Seeders;

use App\Models\CmsPage;
use App\Models\Faq;
use Illuminate\Database\Seeder;

/**
 * Board 2026-10-31 (items 12 + 13): premium copy for every public page except About Us
 * (which carries the board's own text), and a portal-wide FAQ.
 *
 * Idempotent: a page body is (re)written only while it still holds the demo one-liner or
 * is empty — edits made later under Website → Pages are never overwritten. FAQs are keyed
 * on the English question; the four storefront demo questions are retired.
 */
class CmsContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::pages() as $slug => $p) {
            $page = CmsPage::firstOrNew(['slug' => $slug]);
            $current = trim(strip_tags((string) ($page->body['en'] ?? '')));
            $isDemo = $current === '' || mb_strlen($current) < 160;   // the seeded stubs are one sentence
            if (! $page->exists || $isDemo) {
                $page->fill([
                    'group' => $p['group'],
                    'title' => ['en' => $p['title']] + (array) ($page->title ?? []),
                    'body' => ['en' => $p['body']] + (array) ($page->body ?? []),
                    'is_published' => true,
                    'sort' => $page->sort ?: $p['sort'],
                ])->save();
            }
        }

        $demo = ['Do you ship internationally?', 'Is the gold hallmarked?', 'Can I exchange old gold?', 'How do gold savings plans work?'];
        Faq::all()->filter(fn (Faq $f) => in_array($f->question['en'] ?? '', $demo, true))->each->delete();
        foreach (self::faqs() as $i => [$q, $a]) {
            $faq = Faq::all()->first(fn (Faq $f) => ($f->question['en'] ?? null) === $q) ?? new Faq;
            $faq->fill([
                'question' => ['en' => $q] + (array) ($faq->question ?? []),
                'answer' => ['en' => $a] + (array) ($faq->answer ?? []),
                'is_published' => true,
                'sort' => $i + 1,
            ])->save();
        }
    }

    /** @return array<string, array{group:string,title:string,sort:int,body:string}> */
    public static function pages(): array
    {
        return [
            'services' => ['group' => 'page', 'title' => 'Services', 'sort' => 2, 'body' => <<<'HTML'
<p><strong>LORD JEWELLER</strong> is more than a showroom. Every branch — Lord's Branches and authorised dealers alike — offers the same set of services, priced to the minute on the live gold and silver rate, so a customer in Sivakasi and a customer in a district town are served identically.</p>

<h2>Blessed Gold — jewellery and bullion</h2>
<p>HUID-hallmarked, BIS-certified 22K gold and 925 silver: coins from 100 mg to 1 kg, chains, bangles, rings and bridal sets, and a utility range of silver spoons, tumblers and pooja articles. Every piece is billed at the live metal rate plus the published making and wastage charges and GST — the invoice shows each of these lines separately.</p>

<h2>Gold Saving schemes</h2>
<p>Save a fixed amount every month and take delivery in gold at maturity. The monthly instalment can be paid at any branch, or auto-debited through a UPI / bank e-mandate. Each scheme comes with a contract document that is updated after every instalment, a stock QR for the gold that is set aside for you, and a settlement at maturity that credits the full value — with the scheme bonus where applicable.</p>

<h2>Purchase and investment plans</h2>
<p>One-time purchase plans for gold and silver, available at the live rate, with the metal held in branch stock against a redeemable QR until the customer takes delivery. Plans are available in both metals and across a range of ticket sizes.</p>

<h2>Digi Market</h2>
<p>A two-metal digital purse — gold and silver — bought in rupees through the app at the live rate, held in grams, and converted back to cash whenever needed. Ideal for small, regular savings that do not yet justify a physical coin.</p>

<h2>Customised orders</h2>
<p>Have a design made to order in the weight and purity you specify. The branch records your requirement, Head Office quotes and crafts the piece, and it travels back to your branch for delivery and billing at the frozen order price.</p>

<h2>Exchange and buy-back</h2>
<p>Old gold and silver are valued at the live rate after purity testing and credited against a new purchase. Gold Saving coins accumulated under a scheme can be applied as payment on a customised order.</p>

<h2>Hallmarking and certification</h2>
<p>All gold carries the six-character HUID mark under the BIS hallmarking scheme; silver articles are 925 certified. Certificates are issued with the invoice.</p>

<h2>Distributor and dealer network</h2>
<p>Retailers, wholesalers, sub-dealers and area, taluka, district, zonal and regional dealerships operate under Lord Jeweller's supply chain, with stock drawn from their supplying branch and margins settled through the portal. Ask at any branch about joining the network.</p>

<h2>Lord Jeweller app</h2>
<p>Live rates and the daily price list, your contracts and passbook, earnings, Digi Market purse, store locator with each branch's payment QR, support tickets and all notifications — in English, Tamil and Hindi.</p>
HTML],

            'privacy-policy' => ['group' => 'policy', 'title' => 'Privacy Policy', 'sort' => 10, 'body' => <<<'HTML'
<p>This policy explains what personal information <strong>Global Gold Pvt Ltd (LORD JEWELLER)</strong> collects through its website, mobile app and branch counters, why it is collected, and the choices you have. It applies to customers, distributors, dealers and visitors.</p>

<h2>What we collect</h2>
<ul>
<li><strong>Identity and contact</strong> — name, date of birth, father's or husband's name, address, pincode, phone number, e-mail, nominee details.</li>
<li><strong>KYC documents</strong> — PAN and Aadhaar numbers and, where you upload them, images of the cards, as required under the Income-tax Act (Rule 114B) and the Prevention of Money-laundering Act for bullion transactions.</li>
<li><strong>Transactions</strong> — invoices, contracts, Gold Saving instalments, redemptions, Digi Market purchases and withdrawals, wallet movements, commission and payout records.</li>
<li><strong>App and device data</strong> — login phone number and one-time passwords, push-notification token, device model, app version, and — only when you allow it — your location for store search, attendance and L-BOX installation, and your camera for profile photos and document uploads.</li>
<li><strong>Network data</strong> — for distributors, the placement (senior and referring distributor), rank, business volume and team details that run the incentive programme.</li>
</ul>

<h2>Why we use it</h2>
<ul>
<li>To bill, deliver, document and settle what you buy or save with us, and to issue the legally required invoices and certificates.</li>
<li>To verify identity and meet tax and anti-money-laundering obligations, including TDS and reporting of cash transactions above the statutory limits.</li>
<li>To compute and pay distributor incentives, margins and salaries, and to show you your own earnings and network.</li>
<li>To send service messages — rate changes, instalment reminders, contract updates, redemption OTPs — by push notification and app inbox; WhatsApp is used only for one-time passwords and documents you request.</li>
<li>To answer support tickets and improve the app and branch service.</li>
</ul>

<h2>Who sees it</h2>
<p>Your data is processed by Lord Jeweller staff and, for the transactions you make there, by the branch or dealer that serves you. Dealer logins see only masked identifiers (for example 98xxxxxx82) of distributors they did not enrol. We share data with service providers strictly to deliver the service — payment gateway (Razorpay), KYC verification services, SMS/WhatsApp and push-notification providers, video-meeting platform — and with government authorities where the law requires. We never sell personal data.</p>

<h2>Security and retention</h2>
<p>Data is stored on servers located in India, protected by access controls, encrypted transport and role-based permissions. Transaction records are kept for the period required by tax and company law (currently eight years); KYC records for five years after the relationship ends; app and device data until you log out or delete your account.</p>

<h2>Your choices</h2>
<ul>
<li>View and correct your profile, nominee and KYC details in the app under My Profile, or at your branch.</li>
<li>Withdraw location or camera permission at any time in your phone settings; the related features stop working.</li>
<li>Ask us to delete your account from the app (My Profile → Delete account) or by writing to us; records we must keep by law are retained but no longer used for any other purpose.</li>
<li>Opt out of promotional notifications in the app; service messages about your money and contracts continue.</li>
</ul>

<h2>Contact</h2>
<p>Data Protection Officer, Global Gold Pvt Ltd, 62K Gandhi Road, Jehova Nishi Complex, First Floor, Sivakasi, Tamil Nadu 626123. Grievances are acknowledged within 48 hours and resolved within 30 days, as required under the Digital Personal Data Protection Act, 2023.</p>
HTML],

            'terms' => ['group' => 'policy', 'title' => 'Terms of Use', 'sort' => 11, 'body' => <<<'HTML'
<p>These terms govern the use of the LORD JEWELLER website, mobile app and distributor portal operated by <strong>Global Gold Pvt Ltd</strong>, Sivakasi (the "Company"). By creating an account, buying, saving or acting as a distributor or dealer, you accept them.</p>

<h2>1. Accounts</h2>
<p>An account is opened with a mobile number verified by one-time password, and for distributors with a distributor code issued at a branch. You are responsible for every action taken under your login; tell your branch immediately if you suspect misuse. Biometric login is a convenience on your own device and does not replace the OTP for payments.</p>

<h2>2. Prices</h2>
<p>Gold and silver are sold at the live rate published by Head Office at the moment of billing, plus the making and wastage charges shown for the weight bracket, hallmarking charges and GST. The daily price list in the app is indicative; the invoice is final. Rates change during the day and the Company may refuse an order placed on a stale rate.</p>

<h2>3. Purchases, contracts and QR codes</h2>
<p>Every sale is documented by a tax invoice (or a contract for contract-only schemes). Metal that is held in branch stock for you is represented by a redeemable stock QR; it can be redeemed only by the registered customer, at a branch, after a one-time password sent to the customer's app. A QR that has been redeemed, cancelled or has expired has no value.</p>

<h2>4. Gold Saving schemes</h2>
<p>Instalments are due on the scheme's monthly date; a reminder is sent ten days ahead. Missed instalments may delay maturity and the scheme bonus. Settlement at maturity is in gold (or its value, where the scheme so provides) at the live rate on the settlement date. Early closure is at the Company's discretion and may forfeit the bonus.</p>

<h2>5. Digi Market</h2>
<p>Digital gold and silver are bought and sold in grams at the live rate. Withdrawals are to cash only, after the platform fee stated in the app, and are credited to your cash wallet. Digital balances cannot be transferred to another person.</p>

<h2>6. Distributors and dealers</h2>
<p>Sales incentives, turnover-based salary, promotional incentives and dealer margins are computed by the portal under the scheme sheet in force and are credited to a wallet only after Head Office approval, net of statutory TDS and the service charge. Earnings are capped at the value a distributor has contracted. The Company may correct, hold or recover any amount paid in error or in breach of these terms. Dealer stock is supplied against payment or wallet balance and remains subject to audit.</p>

<h2>7. Cancellations and refunds</h2>
<p>See the Refund Policy and Return / Exchange Policy. A bill may be cancelled only by Head Office, and only until its stock QR is redeemed or an instalment is collected on its contract.</p>

<h2>8. Acceptable use</h2>
<p>You may not use the platform to launder money, structure cash transactions, misrepresent KYC details, enrol fictitious distributors or interfere with the service. The Company may suspend an account pending investigation.</p>

<h2>9. Liability</h2>
<p>The Company's liability for any claim is limited to the value of the transaction concerned. It is not liable for delays caused by payment gateways, networks or events beyond its control.</p>

<h2>10. Law and disputes</h2>
<p>These terms are governed by Indian law. Disputes are subject to the exclusive jurisdiction of the courts at Sivakasi, Tamil Nadu, after an attempt at resolution through the in-app support desk.</p>
HTML],

            'shipping-policy' => ['group' => 'policy', 'title' => 'Shipping & Delivery Policy', 'sort' => 12, 'body' => <<<'HTML'
<p>Most of what we sell is handed over at the counter: the invoice, the certificate and the piece leave the branch together. This policy covers the cases where the metal travels.</p>

<h2>Branch pick-up (default)</h2>
<p>Coins and jewellery bought under a purchase plan, Gold Saving settlements and customised orders are collected from the branch that billed them, against the stock QR and the customer's OTP. Pick-up is free and available during branch hours.</p>

<h2>Customised orders</h2>
<p>A made-to-order piece is crafted at Head Office and dispatched to your branch by insured courier. The delivery date fixed at acceptance is shown on the order in the app and the branch informs you when the piece has arrived.</p>

<h2>Online shop orders</h2>
<p>Orders placed in the web shop are delivered by insured courier within India in 5–7 working days after payment confirmation, or can be collected at a branch of your choice. Shipping is free for orders above ₹25,000; otherwise ₹250 is charged. Deliveries require the recipient's signature and a government identity document; we do not deliver to post boxes.</p>

<h2>Risk and insurance</h2>
<p>Every shipment is insured for its full invoice value until it is signed for. Report damage or a tampered seal at delivery and refuse the parcel; a replacement is sent at our cost.</p>

<h2>Outside India</h2>
<p>International delivery is available for foreign distributors on a quotation basis and is subject to the destination country's import rules and duties, which are the recipient's responsibility.</p>
HTML],

            'refund-policy' => ['group' => 'policy', 'title' => 'Refund & Cancellation Policy', 'sort' => 13, 'body' => <<<'HTML'
<p>Gold and silver are priced on a live market, so our refund rules are built around the rate at which you bought and the rate at which the metal comes back.</p>

<h2>Cancelling a bill</h2>
<p>A sales invoice can be cancelled by Head Office until the stock QR issued on it is redeemed or an instalment has been collected on its contract. On cancellation the stock returns to the branch and the contract, coupons, incentives and QR issued on the bill are reversed. The amount paid is refunded to the mode of payment within 7 working days; cash payments are refunded at the branch.</p>

<h2>Online shop orders</h2>
<p>An order can be cancelled free of charge until it is dispatched. After dispatch, refuse the parcel and the refund is processed when it reaches us. Gateway refunds reach your account in 5–7 working days.</p>

<h2>Gold Saving schemes</h2>
<p>Instalments already paid are not refunded in cash during the term; they are settled in gold (or its value) at maturity under the scheme terms. Early closure, where the Company permits it, is settled at the live rate on the closure date without the scheme bonus.</p>

<h2>Digi Market</h2>
<p>A digital purchase cannot be cancelled once executed at the live rate. Grams can be sold back at any time at the live buy-back rate less the platform fee.</p>

<h2>Distributor payments</h2>
<p>Scheme amounts paid by a distributor are governed by the scheme contract. Wallet balances are withdrawn in cash at a dealer branch; a withdrawal request can be cancelled until the branch has paid it.</p>

<h2>How to ask</h2>
<p>Raise a ticket in the app (Support → New ticket) with the invoice number, or contact your branch. Every refund is confirmed by push notification and shows in your app inbox.</p>
HTML],

            'return-policy' => ['group' => 'policy', 'title' => 'Return & Exchange Policy', 'sort' => 14, 'body' => <<<'HTML'
<p>We stand behind every hallmarked piece we sell. This policy sets out how returns, exchanges and buy-backs work.</p>

<h2>Lifetime exchange</h2>
<p>Any hallmarked gold or silver bought from Lord Jeweller can be exchanged at any branch, at any time, for new jewellery or coins. The metal is credited at the live rate for its hallmarked purity and net weight; stones, making and wastage charges on the original piece are not refunded.</p>

<h2>Returns for refund</h2>
<p>A piece bought in the online shop may be returned unused, with its certificate and packaging, within 15 days of delivery. Once we have checked it, the metal value at the lower of the purchase rate and the live rate is refunded, less making and shipping charges. Coins and bars are refunded at the live buy-back rate. Customised pieces cannot be returned for refund.</p>

<h2>Buy-back of old gold</h2>
<p>Gold or silver not bought from us is valued after purity testing at the branch and credited against a new purchase at the live rate less the purity deduction shown on the valuation slip.</p>

<h2>Gold Saving coins</h2>
<p>Coins accumulated under a Gold Saving scheme can be applied as payment on a customised order. The branch records a sales return at the live rate and the value is credited to the order.</p>

<h2>Defects</h2>
<p>A manufacturing defect reported within 30 days is repaired or replaced free of charge. Hallmark disputes are settled by the BIS-recognised assaying centre.</p>

<h2>Conditions</h2>
<p>Bring the original invoice and certificate. Pieces that have been altered, resized elsewhere, or show damage beyond normal wear are valued as old gold.</p>
HTML],
        ];
    }

    /** @return array<int, array{0:string,1:string}> */
    public static function faqs(): array
    {
        return [
            ['What is Lord Jeweller and what is "Blessed Gold"?',
             'Lord Jeweller is the jewellery house of Global Gold Pvt Ltd, headquartered at Sivakasi, Tamil Nadu. Blessed Gold is our HUID-hallmarked, BIS-certified 22K gold and 925 silver, sold at the live metal rate through Lord\'s Branches and an authorised dealer network across Tamil Nadu.'],
            ['How is the price of a coin or ornament worked out?',
             'Live rate per gram × net weight, plus the making and wastage percentage for that weight bracket, plus hallmarking charges, plus GST. Every line is printed separately on the tax invoice. The daily price list in the app shows the same calculation for every catalog item at today\'s rate.'],
            ['Where does the live rate come from and how often does it change?',
             'Head Office publishes the board rate for gold and silver under System → Live Rates. Every change is pushed to all app users as a "price for today" notification showing the new rate and how much it moved. Bills are always raised at the rate in force at the moment of billing.'],
            ['What schemes can I join?',
             'Purchase plans (buy gold or silver outright at the live rate), Gold Saving schemes (a fixed monthly amount saved towards gold), and dealership plans for retailers, wholesalers and area / taluka / district / zonal / regional dealers. Some schemes are available only at Lord\'s Branches; the branch will show you what applies.'],
            ['How does a Gold Saving scheme work?',
             'You choose a monthly amount and a term. Each instalment is paid at your branch or auto-debited by UPI / bank mandate, and your contract passbook in the app updates after every payment. Gold is set aside for you against a stock QR, and at maturity the scheme is settled in gold with the scheme bonus where applicable.'],
            ['When is my instalment due and will I be reminded?',
             'Instalments fall due on the monthly date of your contract. The app sends a reminder ten days before and a follow-up if an instalment is three days overdue. You can pay at any branch or scan the branch\'s payment QR from the store locator.'],
            ['Why does the branch ask for PAN and Aadhaar?',
             'Bullion sales are covered by Income-tax Rule 114B and anti-money-laundering rules, so PAN is required above the statutory limit and identity must be verified. PAN is checked digitally; Aadhaar is verified by OTP or by a card upload reviewed by Head Office. A green "KYC verified" badge appears on your profile once both are done.'],
            ['What is the contract document I receive after billing?',
             'Contract-bearing schemes generate a contract with the scheme terms, your details and — for Gold Saving — a monthly chart that is rebuilt after every instalment. It is pushed to your app inbox and available under Documents; the branch can also print it.'],
            ['What is a stock QR and how do I redeem it?',
             'A stock QR represents the gold or silver held in branch stock for your bill. To take delivery, the counter scans the QR, a one-time password is sent to your app, and once you share it the piece is handed over and the QR is marked redeemed. Only the registered customer can redeem it.'],
            ['Can a bill be changed or cancelled?',
             'Bills are never edited. Head Office can cancel a bill until its stock QR is redeemed or an instalment is collected on its contract; the stock returns to the branch and everything issued on the bill is reversed. Ask your branch to raise the request.'],
            ['What is Digi Market?',
             'A two-metal digital purse. You buy gold or silver in rupees at the live rate from your cash wallet or by card / UPI, hold it in grams, and sell it back to cash whenever you like (a small platform fee applies on exit). Balances cannot be transferred to anyone else.'],
            ['What can I earn as a distributor?',
             'Sales Incentive on the contracts of your team, Turnover-based Salary for the rank you hold, Promotional Incentive coupons on your own contracts, and — for dealers — billing, gold, silver, stock-transfer and Gold Saving renewal margins. The app shows every entry under Earnings.'],
            ['When is an earning credited to my wallet?',
             'Only after Head Office approves it under Commission Approval. At approval 5% TDS and a 5% service charge are withheld (promotional coupons are exempt) and the net amount is credited to your cash wallet. Pending entries are visible in the app with their status.'],
            ['How do I withdraw money from my wallet?',
             'Open Wallet → Withdraw in the app, enter the amount and show the QR to any dealer branch; the branch pays you in cash and the withdrawal is recorded. There is no bank transfer leg.'],
            ['What do "Senior Distributor" and "Referred Distributor" mean?',
             'The referred distributor is the person who introduced you; the senior distributor is the position in the network under which you are placed. Both are fixed when you are enrolled and are shown in your profile and in Head Office\'s Distributor Place folder view.'],
            ['How do I find the nearest branch and pay there?',
             'Store Locator in the app lists every branch with directions on the map. Each branch has its own static payment QR; scan it with any UPI app to pay the branch directly for instalments or purchases, and keep the UPI reference for the counter.'],
            ['Can I order a custom design?',
             'Yes. The branch records the weight, purity and design, Head Office quotes and makes the piece, and it is delivered to your branch for billing at the price frozen on the order. Gold Saving coins you have accumulated can be applied against the order.'],
            ['Is the gold hallmarked?',
             'All gold carries the six-character HUID under the BIS hallmarking scheme and every silver article is 925 certified. Certificates are issued with the invoice; disputes are settled by a BIS-recognised assaying centre.'],
            ['Can I exchange or return jewellery?',
             'Hallmarked pieces bought from us can be exchanged at any branch at any time at the live rate for their net weight. Online-shop purchases can be returned unused within 15 days. See the Return & Exchange Policy for the details.'],
            ['How do I log in to the app?',
             'Enter your registered mobile number and the OTP sent to it. After the first login you can enable fingerprint or face login on that phone. One phone number can hold several accounts (for example a customer and a distributor code); switch between them under My Profile.'],
            ['Why am I not receiving notifications?',
             'Rate changes, reminders, contract updates and OTPs are sent as push notifications and always also appear in the app inbox. Allow notifications for the Lord Jeweller app in your phone settings and keep the app updated. WhatsApp is used only for OTPs and documents you request.'],
            ['How do I get help?',
             'Open Support in the app and raise a ticket with a photo or document attached; the support desk replies in the same thread and you are notified. Branches can also raise tickets on your behalf.'],
            ['What are the online meetings in the app?',
             'Head Office schedules training and review meetings on Zoom for selected ranks. They appear under Meetings with a one-tap join; attendance is recorded automatically and counts towards your monthly duties.'],
        ];
    }
}
