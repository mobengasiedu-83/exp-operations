<?php

return [

    /*
    |--------------------------------------------------------------------------
    | EXP inbound IVR tree
    |--------------------------------------------------------------------------
    |
    | The caller hears this menu on the assigned DID. Only the option recorded
    | as the agent option reaches an agent for screening; every other option is
    | a self-service journey that never creates a case.
    |
    | Labels describe what the caller hears. Change them when the dialplan
    | recordings change so the DID journey screen keeps quoting the real menu.
    |
    */

    'agent_option' => (string) env('EXP_IVR_AGENT_OPTION', '3'),

    'options' => [
        '1' => ['label' => 'Listen to the oral health mission and brush day and night message', 'kind' => 'recording'],
        '2' => ['label' => 'Listen to Pepsodent reach, products and availability information', 'kind' => 'recording'],
        '3' => ['label' => 'Speak to an EXP agent', 'kind' => 'agent'],
    ],

    /*
    | An optional AMI UserEvent name the dialplan emits for each key press, for
    | example "EXPIVRSelection" with a Digit field. Leave empty when selections
    | are already visible as Newexten steps inside EXP_PBX_IVR_CONTEXTS.
    */
    'user_event' => env('EXP_IVR_USER_EVENT'),

];
