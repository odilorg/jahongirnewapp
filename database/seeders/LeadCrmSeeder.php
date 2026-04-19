<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LeadFollowUpStatus;
use App\Enums\LeadFollowUpType;
use App\Enums\LeadInteractionChannel;
use App\Enums\LeadInteractionDirection;
use App\Enums\LeadInterestFormat;
use App\Enums\LeadInterestStatus;
use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\LeadInteraction;
use App\Models\LeadInterest;
use Illuminate\Database\Seeder;

/**
 * Five representative personas covering the main lifecycle states the
 * operator UI must render well. Used to smoke-test the lead detail page
 * and the follow-up queue.
 */
class LeadCrmSeeder extends Seeder
{
    public function run(): void
    {
        $this->soloWaitingOnGuest();
        $this->familyGroupExploring();
        $this->unclearInquiry();
        $this->returningTentative();
        $this->lost();
    }

    private function soloWaitingOnGuest(): void
    {
        $lead = Lead::create([
            'name'              => 'Anna Petrova',
            'phone'             => '+79161234567',
            'whatsapp_number'   => '+79161234567',
            'email'             => 'anna@example.com',
            'preferred_channel' => 'whatsapp',
            'source'            => LeadSource::WhatsAppIn->value,
            'language'          => 'ru',
            'country'           => 'RU',
            'status'            => LeadStatus::Qualified->value,
            'priority'          => LeadPriority::High->value,
        ]);

        $interest = LeadInterest::create([
            'lead_id'        => $lead->id,
            'tour_freeform'  => 'Samarkand + Bukhara 4-day private',
            'requested_date' => now()->addDays(21),
            'pax_adults'     => 1,
            'pax_children'   => 0,
            'format'         => LeadInterestFormat::Private->value,
            'direction_code' => 'sam-buk',
            'pickup_city'    => 'Samarkand',
            'dropoff_city'   => 'Bukhara',
            'status'         => LeadInterestStatus::Quoted->value,
        ]);

        LeadInteraction::create([
            'lead_id'     => $lead->id,
            'channel'     => LeadInteractionChannel::WhatsApp->value,
            'direction'   => LeadInteractionDirection::Inbound->value,
            'body'        => 'Hi, interested in Samarkand-Bukhara private tour end of May.',
            'occurred_at' => now()->subDays(3),
        ]);

        LeadFollowUp::create([
            'lead_id'          => $lead->id,
            'lead_interest_id' => $interest->id,
            'due_at'           => now()->addDay(),
            'type'             => LeadFollowUpType::CheckIn->value,
            'note'             => 'Follow up on quote sent yesterday.',
            'status'           => LeadFollowUpStatus::Open->value,
        ]);
    }

    private function familyGroupExploring(): void
    {
        $lead = Lead::create([
            'name'              => 'Müller Family',
            'email'             => 'muller@example.de',
            'whatsapp_number'   => '+491701234567',
            'preferred_channel' => 'email',
            'source'            => LeadSource::Website->value,
            'language'          => 'en',
            'country'           => 'DE',
            'status'            => LeadStatus::Contacted->value,
            'priority'          => LeadPriority::Medium->value,
        ]);

        LeadInterest::create([
            'lead_id'          => $lead->id,
            'tour_freeform'    => 'Classic Silk Road 7-day family',
            'requested_date'   => now()->addMonths(2),
            'pax_adults'       => 2,
            'pax_children'     => 4,
            'format'           => LeadInterestFormat::Group->value,
            'pickup_city'      => 'Tashkent',
            'dropoff_city'     => 'Tashkent',
            'special_requests' => 'Need child-friendly accommodation; vegetarian meals for 2.',
            'status'           => LeadInterestStatus::Exploring->value,
        ]);

        LeadInteraction::create([
            'lead_id'     => $lead->id,
            'channel'     => LeadInteractionChannel::Email->value,
            'direction'   => LeadInteractionDirection::Inbound->value,
            'subject'     => 'Silk Road family tour enquiry',
            'body'        => 'Family of 6 interested in a 7-day tour in June. Please send options.',
            'occurred_at' => now()->subDays(1),
        ]);

        LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->addHours(4),
            'type'    => LeadFollowUpType::SendQuote->value,
            'note'    => 'Prepare family quote with 2 vegetarian meals.',
            'status'  => LeadFollowUpStatus::Open->value,
        ]);
    }

    private function unclearInquiry(): void
    {
        $lead = Lead::create([
            'name'              => null,
            'phone'             => '+998901112233',
            'whatsapp_number'   => '+998901112233',
            'preferred_channel' => 'whatsapp',
            'source'            => LeadSource::WhatsAppIn->value,
            'language'          => 'ru',
            'country'           => 'UZ',
            'status'            => LeadStatus::New->value,
            'priority'          => LeadPriority::Low->value,
            'notes'             => 'Asked "do you have tours" — no further detail.',
        ]);

        LeadInteraction::create([
            'lead_id'     => $lead->id,
            'channel'     => LeadInteractionChannel::WhatsApp->value,
            'direction'   => LeadInteractionDirection::Inbound->value,
            'body'        => 'Здравствуйте, у вас есть туры?',
            'occurred_at' => now()->subHours(6),
        ]);

        LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->addHours(2),
            'type'    => LeadFollowUpType::Message->value,
            'note'    => 'Ask what kind of tour, dates, how many people.',
            'status'  => LeadFollowUpStatus::Open->value,
        ]);
    }

    private function returningTentative(): void
    {
        $lead = Lead::create([
            'name'              => 'David Chen',
            'email'             => 'david.chen@example.com',
            'whatsapp_number'   => '+14155550123',
            'preferred_channel' => 'email',
            'source'            => LeadSource::Referral->value,
            'language'          => 'en',
            'country'           => 'US',
            'status'            => LeadStatus::Tentative->value,
            'priority'          => LeadPriority::High->value,
            'waiting_reason'    => 'Waiting for group of 4 friends to confirm dates.',
        ]);

        LeadInterest::create([
            'lead_id'        => $lead->id,
            'tour_freeform'  => 'Nurata yurt camp + Aydarkul lake',
            'requested_date' => now()->addWeeks(6),
            'pax_adults'     => 4,
            'pax_children'   => 0,
            'format'         => LeadInterestFormat::Private->value,
            'pickup_city'    => 'Samarkand',
            'dropoff_city'   => 'Samarkand',
            'status'         => LeadInterestStatus::Tentative->value,
        ]);

        LeadInteraction::create([
            'lead_id'      => $lead->id,
            'channel'      => LeadInteractionChannel::Email->value,
            'direction'    => LeadInteractionDirection::Outbound->value,
            'subject'      => 'Re: Yurt camp availability',
            'body'         => 'Dates held until Friday. Let me know once friends confirm.',
            'is_important' => true,
            'occurred_at'  => now()->subDays(2),
        ]);

        LeadFollowUp::create([
            'lead_id' => $lead->id,
            'due_at'  => now()->addDays(3),
            'type'    => LeadFollowUpType::CheckIn->value,
            'note'    => 'Friday hold deadline — check if group confirmed.',
            'status'  => LeadFollowUpStatus::Open->value,
        ]);
    }

    private function lost(): void
    {
        $lead = Lead::create([
            'name'     => 'Marco Rossi',
            'email'    => 'marco@example.it',
            'source'   => LeadSource::Website->value,
            'language' => 'en',
            'country'  => 'IT',
            'status'   => LeadStatus::Lost->value,
            'priority' => LeadPriority::Low->value,
            'notes'    => 'Chose competitor — said our price was 15% higher.',
        ]);

        LeadInteraction::create([
            'lead_id'      => $lead->id,
            'channel'      => LeadInteractionChannel::Email->value,
            'direction'    => LeadInteractionDirection::Inbound->value,
            'body'         => 'Thanks, but we decided to go with another agency.',
            'is_important' => true,
            'occurred_at'  => now()->subWeeks(2),
        ]);
    }
}
