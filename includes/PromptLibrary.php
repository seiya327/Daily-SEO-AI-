<?php

declare(strict_types=1);

namespace DSAP;

final class PromptLibrary
{
    public static function editorialStandard(): string
    {
        return <<<'PROMPT'
Editorial standard for every Japanese article:
- Satisfy the exact query and reader situation in the opening. Do not start with generic background, a dictionary definition, or a long preamble.
- Add information gain. Include concrete decision criteria, procedures, examples, failure patterns, exceptions, cautions, and who should or should not choose the option.
- Separate sourced facts from editorial judgment. Never fabricate experience, interviews, survey results, prices, rankings, testimonials, or product capabilities.
- Use specific headings that communicate an answer. Avoid repetitive headings, empty transitions, keyword stuffing, and paragraphs that merely restate the heading.
- Write for a reader making a real decision. Resolve the main objection before the CTA and make the next action a natural continuation of the article.
- Attraction articles must solve the immediate problem first, then bridge to one relevant CV article. CV articles must compare options fairly, disclose trade-offs, identify fit and non-fit, and explain the decision process before presenting the approved offer.
- Do not claim guaranteed SEO results or guaranteed conversions. Do not imitate first-hand experience unless it exists in the supplied evidence.
- Output clean WordPress HTML with H2/H3, paragraphs, lists, and tables only when they improve understanding. Do not output H1, scripts, styles, iframes, forms, affiliate links, or a references section; the publisher adds approved links and references.
PROMPT;
    }

    public static function strategyStandard(): string
    {
        return <<<'PROMPT'
Strategy standard:
- Start from the offer, the conversion action, customer anxieties, switching triggers, failed alternatives, and moments when the problem becomes urgent.
- Build clusters as complete decision journeys: unaware/problem-aware entry -> solution exploration -> comparison/objection handling -> CV article.
- Each cluster needs at least one CV article and at least two attraction articles. Every attraction article must name one CV target keyword in the same plan.
- At least 70% of attraction keywords must be specific long-tail queries. Prefer situations, constraints, mistakes, hidden costs, alternatives, timing, audience roles, and objections over obvious category terms.
- Do not create two articles for substantially the same intent. Do not repeat existing focus keywords or titles supplied in site context.
- A brief must define the reader situation, hidden pain, unique entry angle, promised outcome, information gain, conversion bridge, and objection to resolve. Generic briefs are invalid.
- Do not confuse traffic volume with business value. Prioritize reachable demand with a plausible path to the configured conversion.
PROMPT;
    }

    public static function auditStandard(): string
    {
        return <<<'PROMPT'
Audit standard:
- Score the delivered article, not the plan or the writer's intent.
- Treat unsupported claims, intent drift, generic filler, missing decision support, weak differentiation, and forced conversion language as material defects.
- A high score requires information gain, source discipline, a trustworthy treatment of trade-offs, and a natural next step.
- Give revision instructions that are concrete enough for another writer to apply without guessing.
PROMPT;
    }
}
