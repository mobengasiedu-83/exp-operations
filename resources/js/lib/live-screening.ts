type Question = { id: number; prompt: string };
type Answer = { question_id: number; answer: string };

export const summaryQuestions = {
    concern: "Please tell me, in your own words, what made you contact us today.",
    onset: "When did you first notice it?",
    impact: "Is it affecting eating, drinking, sleeping or speaking?",
} as const;

/** Reuse only explicitly matching questions; custom protocols keep their own summary fields. */
export function screeningSummary(
    questions: Question[],
    answers: Answer[],
    fallback: { concern: string; onset: string; impact: string },
): { concern: string; onset: string; impact: string } {
    const summary = { ...fallback };
    for (const field of ["concern", "onset", "impact"] as const) {
        const question = questions.find((item) => item.prompt === summaryQuestions[field]);
        const answer = answers.find((item) => item.question_id === question?.id)?.answer.trim();
        if (answer) {
            summary[field] = answer.slice(
                0,
                field === "onset" ? 500 : field === "impact" ? 1000 : 2000,
            );
        }
    }
    return summary;
}
