<?php

namespace Database\Seeders;

use App\Models\ScreeningProtocol;
use Illuminate\Database\Seeder;

class ScreeningProtocolSeeder extends Seeder
{
    /** Version reference of the seeded, already-approved questionnaire. */
    public const VERSION = 'exp-2026-11-19-v1';

    /**
     * Seed the ten supplied screening questions as the active questionnaire.
     * The wording is fixed by the blueprint; administrators create a new
     * version to change it, so existing records keep their snapshots.
     */
    public function run(): void
    {
        $protocol = ScreeningProtocol::query()->firstOrCreate(['version' => self::VERSION], [
            'title' => 'EXP agent screening questionnaire',
            'status' => 'active',
            'is_active' => true,
            'privacy_notice' => 'I will record only the details needed to understand your concern and connect you with a dentist. Your details are used for this service under the EXP privacy policy. You can ask me to stop at any time and end the call. Is that okay?',
            'allow_guardian' => false,
            'guidance' => [
                'P1' => 'Seek urgent emergency medical care now: advise the caller to call 999 or go to the nearest emergency department without waiting for a dentist callback. Stay on the line, notify the supervisor and record the escalation.',
                'P2' => 'Offer a priority clinician handover or the approved urgent in-person referral route. Do not promise a response time the service has not confirmed.',
                'P3' => 'Offer a routine clinician handover or a callback request within the confirmed service hours.',
                'S' => 'Answer only from the approved service information FAQ. Personal clinical concerns must be handled by a dentist.',
                'Review' => 'Record the unanswered or unknown answers and notify the supervisor for clinical review. Do not close the call as resolved.',
            ],
            'faq_answer' => 'The service helps callers reach a dentist. The assistant organises access and a dentist provides clinical judgement; the assistant does not diagnose or advise on treatment.',
        ]);

        if ($protocol->questions()->doesntExist()) {
            foreach ($this->questions() as $position => $question) {
                $protocol->questions()->create([...$question, 'position' => $position, 'positive_priority' => $question['positive_priority'] ?? 'P3']);
            }
        }

        $protocol->activate();
    }

    /**
     * The ten approved questions with their answer types, options and safety rules.
     *
     * @return list<array<string, mixed>>
     */
    public function questions(): array
    {
        return [
            ['prompt' => 'Please tell me, in your own words, what made you contact us today.', 'type' => 'paragraph'],
            ['prompt' => 'Where in your mouth are you experiencing the problem?', 'type' => 'text'],
            ['prompt' => 'When did you first notice it?', 'type' => 'text'],
            ['prompt' => 'Is it constant, or does it come and go?', 'type' => 'choice', 'options' => ['Constant', 'Comes and goes', 'Unsure']],
            ['prompt' => 'How would you describe it—mild, moderate or severe?', 'type' => 'choice', 'options' => ['Mild', 'Moderate', 'Severe', 'Unsure']],
            ['prompt' => 'Is it affecting eating, drinking, sleeping or speaking?', 'type' => 'text', 'help_text' => 'Record everything that applies, then add the caller\'s notes.'],
            ['prompt' => 'Have you noticed swelling in your face, jaw, mouth or neck?', 'type' => 'yes_no_unknown', 'safety_rule' => 'urgent', 'positive_priority' => 'P2', 'help_text' => 'Record any detail in the case notes.'],
            ['prompt' => 'Are you having any difficulty breathing or swallowing?', 'type' => 'yes_no_unknown', 'safety_rule' => 'emergency', 'positive_priority' => 'P1', 'help_text' => 'Record any detail in the case notes. A yes answer is an emergency.'],
            ['prompt' => 'Is there bleeding that will not stop, or has a tooth or part of your mouth been injured?', 'type' => 'yes_no_unknown', 'safety_rule' => 'emergency', 'positive_priority' => 'P1', 'help_text' => 'Record any detail in the case notes. A yes answer is an emergency.'],
            ['prompt' => 'Have you already spoken to a dentist or received treatment for this concern?', 'type' => 'yes_no_unknown', 'help_text' => 'Record the dentist, practice and treatment details in the case notes.'],
        ];
    }
}
