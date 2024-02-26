# exvm2

Experimental Virtual Machine 2.

## What

This is a breaking change evolution of the original [ExVM](https://github.com/0xABADCAFE/exvm).

## Key Changes from v1

### Registers
The original 16 64-bit fully general purpose registers have been split into a more conventional layout:
- 16 64-bit integer/pointer registers.
- 16 64-bit floating point registers.

This reduces pressure on available registers where mixed itneger/floating point code is in use.

### Instruction Set Changes

The instruction set has been reworked to allow more instructions to fit in the core instruction set:
- More consistent ordering of enumerations based on implementation level and type.
- Several opcode groups reduced to single opcodes to free up space for other operations, e.g.
    - Integer divsion and modulus
    - Call, Call Indirect, Call Native, Call Native Indirect
    - Push, Pop, Save, Restore
    - Min, Max
- Comparison types enumerated:
    - Compare and branch reduced to single operation.
    - Conditional move operation added.
- Support for immediate source operands for a number of arithmetic and logical operations.
- New instructions added to the core opcode set, or moved from extension sets:
    - Load indirect scaled indexed
    - Store indirect scaled indexed
    - Decrement and branch if not zero
    - Dereference and branch if not null
    - Classify float and branch
    - Multiply-Accumulate
    - Sqrt, Reciprocal, Ceil, Floor, Power
