---
description: Implement technical plans from thoughts/shared/plans with verification
---

# Implement Plan

You are tasked with implementing an approved technical plan from `thoughts/shared/plans/`. These plans contain phases with specific changes and success criteria.

## Getting Started

When given a plan path:
- Read the plan completely and check for any existing checkmarks (- [x])
- Read the original ticket and all files mentioned in the plan
- **Read files fully** - never use limit/offset parameters, you need complete context
- Think deeply about how the pieces fit together
- Create a todo list to track your progress
- Start implementing if you understand what needs to be done

If no plan path provided, ask for one.

## Implementation Philosophy

Plans are carefully designed, but reality can be messy. Your job is to:
- Follow the plan's intent while adapting to what you find
- Implement each phase fully before moving to the next
- **Parallelize independent work** using worktree-isolated agents where the plan identifies parallel tracks
- Verify your work makes sense in the broader codebase context
- Update checkboxes in the plan as you complete sections

When things don't match the plan exactly, think about why and communicate clearly. The plan is your guide, but your judgment matters too.

If you encounter a mismatch:
- STOP and think deeply about why the plan can't be followed
- Present the issue clearly:
  ```
  Issue in Phase [N]:
  Expected: [what the plan says]
  Found: [actual situation]
  Why this matters: [explanation]

  How should I proceed?
  ```

## Verification Approach

After implementing a phase:
- Run the success criteria checks (usually `make check test` covers everything)
- Fix any issues before proceeding
- Update your progress in both the plan and your todos
- Check off completed items in the plan file itself using Edit
- **Pause for human verification**: After completing all automated verification for a phase, pause and inform the human that the phase is ready for manual testing. Use this format:
  ```
  Phase [N] Complete - Ready for Manual Verification

  Automated verification passed:
  - [List automated checks that passed]

  Please perform the manual verification steps listed in the plan:
  - [List manual verification items from the plan]

  Let me know when manual testing is complete so I can proceed to Phase [N+1].
  ```

If instructed to execute multiple phases consecutively, skip the pause until the last phase. Otherwise, assume you are just doing one phase.

do not check off items in the manual testing steps until confirmed by the user.


## Parallel Execution with Worktree Isolation

When a plan contains a **Parallel Execution Strategy** section (or when you identify 3+ independent changes within a phase), use worktree-isolated agents to implement them concurrently:

### When to Parallelize
- The plan explicitly defines parallel tracks
- A phase has 3+ independent file groups with no shared dependencies
- Tests can be written independently from the implementation they test

### How to Execute
1. **Identify independent tracks** from the plan's parallel execution strategy
2. **Spawn agents in a single message** so they run concurrently:
   - Use `Agent` tool with `isolation: "worktree"` for each track
   - Give each agent a clear, self-contained prompt with:
     - The specific files to modify and exact changes needed
     - The plan context relevant to their track only
     - Instructions to run linting/style fixes on their changed files
3. **Wait for all agents to complete**
4. **Review each agent's changes** before accepting them — verify the diff matches the plan's intent
5. **Merge worktrees** in the order specified by the plan
6. **Run the full test suite** after merging all tracks to catch integration issues

### What NOT to Parallelize
- Changes where one file depends on another agent's output
- Database migrations (always sequential)
- Changes to the same file from multiple agents
- Config changes that affect other tracks' behavior

### Example
For a plan with these independent changes:
```
Track A: Update 3 Livewire components (SaveSearchPopup, SaveSearchPopupV2, CreateAlerts)
Track B: Update 4 blade templates (favourite icons, login footer)
Track C: Write tests for all the above
```

Spawn 3 worktree-isolated agents simultaneously — each makes their changes in isolation, then merge A → B → C and run tests.

## If You Get Stuck

When something isn't working as expected:
- First, make sure you've read and understood all the relevant code
- Consider if the codebase has evolved since the plan was written
- Present the mismatch clearly and ask for guidance

Use sub-tasks sparingly - mainly for targeted debugging or exploring unfamiliar territory.

## Resuming Work

If the plan has existing checkmarks:
- Trust that completed work is done
- Pick up from the first unchecked item
- Verify previous work only if something seems off

Remember: You're implementing a solution, not just checking boxes. Keep the end goal in mind and maintain forward momentum.
