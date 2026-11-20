# Call journey rollout

The current phase audit, remaining work, roster instructions and exact-ID IVR backfill procedure are maintained in [EXP_Remaining_Implementation.md](EXP_Remaining_Implementation.md).

The former statement that all journey work was complete was too broad: application code, local rollout and real-PBX acceptance are separate. The IVR export importer is now implemented as `exp:import-ivr-records`; automated export delivery, PBX-only journey reconstruction, live transfer validation and production acceptance remain open.
