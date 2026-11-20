import assert from "node:assert/strict";
import { test } from "node:test";
import { screeningSummary, summaryQuestions } from "../../resources/js/lib/live-screening.ts";

const fallback = { concern: "Earlier concern", onset: "Earlier onset", impact: "Earlier impact" };

test("questionnaire answers supply the handover without repeated entry", () => {
    const questions = Object.entries(summaryQuestions).map(([field, prompt], index) => ({
        id: index + 1,
        prompt,
        field,
    }));
    const result = screeningSummary(
        questions,
        [
            { question_id: 1, answer: " Tooth pain " },
            { question_id: 2, answer: "Yesterday" },
            { question_id: 3, answer: "Cannot chew" },
        ],
        fallback,
    );
    assert.deepEqual(result, { concern: "Tooth pain", onset: "Yesterday", impact: "Cannot chew" });
    assert.equal(fallback.concern, "Earlier concern");
});

test("custom and unanswered questions do not erase saved caller details", () => {
    assert.deepEqual(
        screeningSummary(
            [{ id: 1, prompt: "A different question" }],
            [{ question_id: 1, answer: "Unrelated answer" }],
            fallback,
        ),
        fallback,
    );
    assert.deepEqual(
        screeningSummary(
            [{ id: 1, prompt: summaryQuestions.concern }],
            [{ question_id: 1, answer: "   " }],
            fallback,
        ),
        fallback,
    );
});

test("handover summaries respect the destination field limits", () => {
    const questions = [
        { id: 1, prompt: summaryQuestions.onset },
        { id: 2, prompt: summaryQuestions.impact },
    ];
    const result = screeningSummary(
        questions,
        [
            { question_id: 1, answer: "x".repeat(900) },
            { question_id: 2, answer: "y".repeat(1500) },
        ],
        fallback,
    );
    assert.equal(result.onset.length, 500);
    assert.equal(result.impact.length, 1000);
});
