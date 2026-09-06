<?php
/**
 * includes/chatbot_knowledge_base.php
 *
 * Condensed nutrition knowledge base for the AI Assistant.
 * Keep short — embedded in every system prompt alongside child data & history.
 */

function chatbot_compile_knowledge_base(): string
{
    return <<<'KB'
CLASSIFICATIONS: WAZ=Weight-for-Age, HAZ=Height-for-Age, WHZ=Weight-for-Height.
WFA: Normal | MUW (Moderately Underweight) | SUW (Severely Underweight).
HFA: Normal | MSt (Moderately Stunted) | SSt (Severely Stunted) | Tall.
WFH: Normal | MW (Moderately Wasted/MAM) | SW (Severely Wasted/SAM) | OW (Overweight) | Ob (Obese).

INTERVENTIONS: Normal = continue feeding. MUW = increase feeding frequency, nutrient-dense foods (egg, fish, lugaw). SUW = REFER to health facility. MSt/SSt = diversified diet, address infections. MW = energy-dense foods, treat illness, re-measure in 2-4 weeks. SW = REFER IMMEDIATELY (OTP with RUTF). OW/Ob = balanced diet, physical activity.

e-OPT PLUS: Monthly = ALL 0-23mo + malnourished 24-59mo. Quarterly = Normal 24-59mo (April/July/October).

FILIPINO: Timbang=weight, Taas=height, Bata=child, Sanggol=infant, Nutrisyon=nutrition, Mababa ang timbang=underweight, Mababa ang taas=stunted, Payat=wasted, Mataba=overweight, Lugaw=porridge, Saging=banana, Itlog=egg, Isda=fish, Doktor=doctor.

FEEDING: 0-6mo = exclusive breastfeeding. 6-23mo = complementary feeding (lugaw, squash, banana, egg, fish). Avoid honey before 1 year, whole nuts.
KB;
}
