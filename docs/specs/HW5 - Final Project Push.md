---
title: "HW 5 - Final Project Push"
source: "https://canvas.ucsd.edu/courses/76795/assignments/1166821"
author:
published:
created: 2026-09-02
description:
tags:
  - "clippings"
---
The final push of the analytics platform requires us to finish fleshing out the backend and close the loop between collecting data and someone (maybe us) acting upon it. While each student group may do different levels of polish and effort, this specification indicates what is considered a minimum to receive maximum points. Notice that you likely have 15 of the final points as the progress was subtracted from the project total.

## Musts

**Your system must:**

- Have a full working authentication system with authorization rules for access. You must have three levels of users: super admin, analyst, and viewer. A super admin can do anything, including managing users. An analyst can do anything and look at anything. An analyst can be defined and may look at a defined set of sections in the backend. For example, an analyst "Sam" may be in charge of performance and can only look at performance data and define "reports," while an analyst "Sally" may be able to look at performance and behavioral sections. A viewer can only look at saved reports, which are just set views, even if they are made static.
- Have an export system. The best way to do this is to generate a PDF of a page using a package and save it (accessible URL) or send it (email).
- Have at least three different types of report categories and utilize appropriate charts and data tables. Reports may also have "analyst comments," which are just text items written where you are decoding the meaning of the data you have looked into. Fancy versions may have HTML/Markdown formatting, but plain text will be allowed to keep it simple. This part of the assignment has room for subjectivity, and if you are sloppy and just meet the syntax requirements of having a chart, table, and text, you may receive only half for this section. Put some thought into this part, as it is the deliverable and the "presentational visualization" discussed in class.

## Shoulds

**Your system should:**

- Be visually organized in a consistent fashion. Using a CSS framework like Bootstrap, Tailwind, or others can help here
- Should care for cases that are unexpected, like 403 pages, 404 pages, script off handling, and other contingencies you can think of. A server-side program is under our control, so exercising that control demonstrates that we are taking advantage of the medium's characteristics.
- Be reasonably performant. If you are delivering over a megabyte of JavaScript code before even getting to your data, it better be worth it. Be careful that vibe-coded solutions may look pretty at first, but be so heavyweight that the graders find it frustrating to use. That would obviously impact your grade.

### Turn-In Details

All project turn-ins must provide a **README.md** file and a **GRADER.md** file. The README.md discusses the technical particulars of the project **(link to the repo and link to the deployed sites)**, mentions the use of AI, if any, and your observation of its value or not, and provides a roadmap of things you would like to have done, time permitting, or in the future. The GRADER.md file contains credentials for all levels of login (super admin, admin, and reporter), a written scenario you would like the grader to try before they free-form use your project (step 1 do this, step 2 do that,...), and a discussion of what areas you are concerned about from a bug or architecture point of view. If you are clear about this, the grader will not deduct points at full consequence, only half consequence. Acceptance of flaws is a good skill for a software engineer -- we can more easily fix the flaws we admit to, so aim for accountability over perfection for maximum leniency.

Note: We are allowing turn-in until Sunday night but I would not encourage that. Turn-ins by Friday midnight will be given more leniency than later turn-ins and help us get the grading done in an expedient manner.  

### Extra Credit Possibilities Abound

We will cap extra credit at 1/3 of the project's total, but you are free to exceed what is written here. Given that much of this is given in the tutorials at CSE135.site (albeit not put together) consider this the floor. We are happy to see more, particularly in visualizations, so if you are motivated, improve your chances for a top mark, get creative!